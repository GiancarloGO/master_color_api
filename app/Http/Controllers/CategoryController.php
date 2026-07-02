<?php

namespace App\Http\Controllers;

use App\Classes\ApiResponseClass;
use App\Http\Requests\CategoryStoreRequest;
use App\Http\Requests\CategoryUpdateRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $categories = Category::withCount('products')
                ->orderBy('name')
                ->get();

            return ApiResponseClass::sendResponse(
                CategoryResource::collection($categories),
                'Lista de categorías',
                200
            );
        } catch (\Exception $e) {
            Log::error('Error fetching categories: ' . $e->getMessage());
            return ApiResponseClass::errorResponse('Error interno del servidor', 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CategoryStoreRequest $request)
    {
        try {
            $validated = $request->validated();

            $category = Category::create([
                'name'   => $validated['name'],
                'slug'   => $this->uniqueSlug($validated['name']),
                'active' => $validated['active'] ?? true,
            ]);

            return ApiResponseClass::sendResponse(
                new CategoryResource($category),
                'Categoría creada exitosamente',
                201
            );
        } catch (\Exception $e) {
            Log::error('Error creating category: ' . $e->getMessage());
            return ApiResponseClass::errorResponse('Error interno del servidor', 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Category $category)
    {
        try {
            $category->loadCount('products');

            return ApiResponseClass::sendResponse(
                new CategoryResource($category),
                'Detalle de categoría',
                200
            );
        } catch (\Exception $e) {
            Log::error('Error fetching category: ' . $e->getMessage(), ['category_id' => $category->id]);
            return ApiResponseClass::errorResponse('Error interno del servidor', 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(CategoryUpdateRequest $request, Category $category)
    {
        try {
            $validated = $request->validated();

            if (array_key_exists('name', $validated)) {
                $category->name = $validated['name'];
                $category->slug = $this->uniqueSlug($validated['name'], $category->id);
            }

            if (array_key_exists('active', $validated) && $validated['active'] !== null) {
                $category->active = $validated['active'];
            }

            $category->save();

            return ApiResponseClass::sendResponse(
                new CategoryResource($category),
                'Categoría actualizada correctamente',
                200
            );
        } catch (\Exception $e) {
            Log::error('Error updating category: ' . $e->getMessage());
            return ApiResponseClass::errorResponse('Error interno del servidor', 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Category $category)
    {
        try {
            $productsCount = $category->products()->count();

            if ($productsCount > 0) {
                return ApiResponseClass::errorResponse(
                    "No se puede eliminar la categoría porque tiene {$productsCount} producto(s) asociado(s).",
                    409
                );
            }

            $category->delete();

            return ApiResponseClass::sendResponse(
                null,
                'Categoría eliminada correctamente',
                200
            );
        } catch (\Exception $e) {
            Log::error('Error deleting category: ' . $e->getMessage());
            return ApiResponseClass::errorResponse('Error interno del servidor', 500);
        }
    }

    /**
     * Genera un slug único a partir del nombre, ignorando opcionalmente un id.
     */
    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i = 2;

        while (
            Category::where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
