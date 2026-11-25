<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Http\Requests\ProductStoreRequest;
use App\Http\Requests\ProductUpdateRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;

class ProductService
{
    public function __construct(
        private FileUploadService $fileUploadService,
        private StockService $stockService,
        private StockMovementService $stockMovementService,
        private AuditService $audit
    ) {}

    /**
     * Resuelve el id de categoría a partir del slug/nombre enviado en `category`.
     * Si la categoría no existe todavía, la crea (soporta alta al vuelo).
     */
    private function resolveCategoryId(?string $categoryValue): ?int
    {
        if ($categoryValue === null || trim($categoryValue) === '') {
            return null;
        }

        $slug = \Illuminate\Support\Str::slug($categoryValue);
        if ($slug === '') {
            return null;
        }

        $category = Category::firstOrCreate(
            ['slug' => $slug],
            ['name' => \Illuminate\Support\Str::title(str_replace('-', ' ', $slug)), 'active' => true]
        );

        return $category->id;
    }

    public function getAllProducts(int $perPage = 15): LengthAwarePaginator
    {
        return Cache::remember('products_paginated_' . $perPage . '_' . request('page', 1), 300, function () use ($perPage) {
            return Product::with(['user', 'stock', 'productCategory'])
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);
        });
    }

    public function getProductById(int $id): ?Product
    {
        return Cache::remember("product_{$id}", 600, function () use ($id) {
            return Product::with(['user', 'stock', 'productCategory'])->find($id);
        });
    }

    public function createProduct(ProductStoreRequest $request): Product
    {
        DB::beginTransaction();
        
        try {
            $validated = $request->validated();
            
            $imagePath = null;
            if ($request->hasFile('image')) {
                $imagePath = $this->fileUploadService->uploadImage(
                    $request->file('image'),
                    'products',
                    'product'
                );
            }

            // Separate product data from stock data
            $stockData = [
                'quantity' => 0, // Start with 0, will be set by stock movement
                'min_stock' => $validated['min_stock'] ?? 0,
                'max_stock' => $validated['max_stock'] ?? 0,
                'purchase_price' => $validated['purchase_price'],
                'sale_price' => $validated['sale_price'],
            ];

            $productData = array_merge($validated, [
                'image_url' => $imagePath,
                'user_id' => Auth::id(),
                'category_id' => $this->resolveCategoryId($validated['category'] ?? null),
            ]);

            // Remove stock fields and image from product data
            unset($productData['image'], $productData['quantity'], $productData['min_stock'], 
                  $productData['max_stock'], $productData['purchase_price'], $productData['sale_price']);

            $product = Product::create($productData);

            $stock = $this->stockService->createInitialStock($product, $stockData);

            // Create initial stock movement if quantity > 0
            $initialQuantity = $validated['quantity'] ?? 0;
            if ($initialQuantity > 0) {
                $this->stockMovementService->createMovement([
                    'movement_type' => 'entrada',
                    'reason' => 'Stock inicial del producto',
                    'voucher_number' => 'INIT-' . $product->id,
                    'stocks' => [[
                        'stock_id' => $stock->id,
                        'quantity' => $initialQuantity,
                        'unit_price' => $stockData['purchase_price']
                    ]]
                ]);
            }

            $this->clearProductCache();

            $actor = Auth::user();
            if ($actor instanceof User) {
                $this->audit->logStaffAction(
                    $actor,
                    'product.created',
                    'Product',
                    $product->id,
                    null,
                    array_merge($this->auditSnapshot($product->load('stock')), [
                        'initial_quantity' => (int) $initialQuantity,
                    ]),
                    ['product_name' => $product->name, 'sku' => $product->sku]
                );
            }

            DB::commit();

            return $product->load(['user', 'stock', 'productCategory']);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            if (isset($imagePath)) {
                $this->fileUploadService->deleteImage($imagePath);
            }
            
            throw $e;
        }
    }

    public function updateProduct(ProductUpdateRequest $request, int $id): ?Product
    {
        $product = Product::with('stock')->find($id);
        
        if (!$product) {
            return null;
        }

        DB::beginTransaction();
        
        try {
            $oldSnapshot = $this->auditSnapshot($product);
            $validated = $request->validated();
            $oldImagePath = $product->image_url;

            if ($request->hasFile('image')) {
                $this->fileUploadService->deleteImage($oldImagePath);
                
                $validated['image_url'] = $this->fileUploadService->uploadImage(
                    $request->file('image'),
                    'products',
                    'product'
                );
            }

            // Separate stock data from product data
            $stockData = [];
            $stockFields = ['min_stock', 'max_stock', 'purchase_price', 'sale_price'];
            
            foreach ($stockFields as $field) {
                if (array_key_exists($field, $validated)) {
                    $stockData[$field] = $validated[$field];
                    unset($validated[$field]);
                }
            }

            unset($validated['image']);

            if (array_key_exists('category', $validated)) {
                $validated['category_id'] = $this->resolveCategoryId($validated['category']);
            }

            $product->update($validated);

            // Update stock if stock data provided
            if (!empty($stockData)) {
                $this->stockService->updateStock($product->id, $stockData);
            }

            $product->refresh()->load('stock');
            $newSnapshot = $this->auditSnapshot($product);
            [$changedOld, $changedNew] = $this->diffSnapshots($oldSnapshot, $newSnapshot);

            $actor = Auth::user();
            if ($actor instanceof User && $changedNew !== []) {
                $this->audit->logStaffAction(
                    $actor,
                    'product.updated',
                    'Product',
                    $product->id,
                    $changedOld,
                    $changedNew,
                    ['product_name' => $product->name, 'sku' => $product->sku]
                );
            }
            
            $this->clearProductCache($id);
            
            DB::commit();
            
            return $product->load(['user', 'stock', 'productCategory']);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            if (isset($validated['image_url'])) {
                $this->fileUploadService->deleteImage($validated['image_url']);
            }
            
            throw $e;
        }
    }

    public function deleteProduct(int $id): bool
    {
        $product = Product::with('stock')->find($id);
        
        if (!$product) {
            return false;
        }

        DB::beginTransaction();
        
        try {
            $snapshot = $this->auditSnapshot($product);
            $this->fileUploadService->deleteImage($product->image_url);
            
            $product->delete();

            $actor = Auth::user();
            if ($actor instanceof User) {
                $this->audit->logStaffAction(
                    $actor,
                    'product.deleted',
                    'Product',
                    $product->id,
                    $snapshot,
                    null,
                    ['product_name' => $product->name, 'sku' => $product->sku]
                );
            }
            
            $this->clearProductCache($id);
            
            DB::commit();
            
            return true;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function searchProducts(string $query, int $perPage = 15): LengthAwarePaginator
    {
        return Product::with(['user', 'stock', 'productCategory'])
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('sku', 'like', "%{$query}%")
                  ->orWhere('barcode', 'like', "%{$query}%")
                  ->orWhere('brand', 'like', "%{$query}%")
                  ->orWhere('category', 'like', "%{$query}%");
            })
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function getProductsByCategory(string $category, int $perPage = 15): LengthAwarePaginator
    {
        return Cache::remember("products_category_{$category}_{$perPage}_" . request('page', 1), 300, function () use ($category, $perPage) {
            return Product::with(['user', 'stock', 'productCategory'])
                ->where('category', $category)
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);
        });
    }

    private function clearProductCache(?int $productId = null): void
    {
        if ($productId) {
            Cache::forget("product_{$productId}");
        }
        
        Cache::flush();
    }

    /**
     * Captura los campos auditables del producto y su stock asociado.
     *
     * @return array<string, mixed>
     */
    private function auditSnapshot(Product $product): array
    {
        $stock = $product->stock;

        return [
            'name' => $product->name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'brand' => $product->brand,
            'description' => $product->description,
            'presentation' => $product->presentation,
            'category' => $product->category,
            'unidad' => $product->unidad,
            'default_warranty_months' => (int) $product->default_warranty_months,
            'image_url' => $product->getRawOriginal('image_url'),
            'purchase_price' => $stock ? (float) $stock->purchase_price : null,
            'sale_price' => $stock ? (float) $stock->sale_price : null,
            'min_stock' => $stock ? (int) $stock->min_stock : null,
            'max_stock' => $stock ? (int) $stock->max_stock : null,
        ];
    }

    /**
     * Devuelve solo los campos que cambiaron entre dos snapshots.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function diffSnapshots(array $old, array $new): array
    {
        $changedOld = [];
        $changedNew = [];

        foreach ($new as $key => $value) {
            $prev = $old[$key] ?? null;
            if ($prev !== $value) {
                $changedOld[$key] = $prev;
                $changedNew[$key] = $value;
            }
        }

        return [$changedOld, $changedNew];
    }
}