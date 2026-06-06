<?php

namespace App\Http\Controllers;

use App\Classes\ApiResponseClass;
use App\Http\Resources\ClientProductResource;
use App\Models\Product;
use Illuminate\Http\Request;

class ClientProductController extends Controller
{
    /**
     * Catálogo de productos para el registro manual de unidades (vista cliente).
     */
    public function index(Request $request)
    {
        try {
            $query = Product::query();

            if ($request->filled('search')) {
                $term = $request->input('search');
                $query->where(function ($q) use ($term) {
                    $q->where('name', 'like', "%{$term}%")
                      ->orWhere('sku', 'like', "%{$term}%");
                });
            }

            $products = $query->orderBy('name')
                ->paginate($request->input('per_page', 15));

            return ApiResponseClass::sendPaginatedResponse(
                ClientProductResource::collection($products),
                $products,
                'Catálogo de productos',
                200
            );
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al obtener productos', 500, [$e->getMessage()]);
        }
    }
}
