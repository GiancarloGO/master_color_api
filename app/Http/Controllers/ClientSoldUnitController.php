<?php

namespace App\Http\Controllers;

use App\Classes\ApiResponseClass;
use App\Http\Resources\SoldUnitResource;
use App\Services\SoldUnitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ClientSoldUnitController extends Controller
{
    public function __construct(private SoldUnitService $soldUnits) {}

    /**
     * Listar las unidades del cliente autenticado.
     */
    public function index(Request $request)
    {
        try {
            $client = Auth::guard('client')->user();

            if (!$client) {
                return ApiResponseClass::errorResponse('No autenticado', 401);
            }

            $query = $client->soldUnits()->with('product');

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('warranty')) {
                if ($request->warranty === 'vigente') {
                    $query->warrantyActive();
                } elseif ($request->warranty === 'vencida') {
                    $query->whereNotNull('warranty_expires_at')
                          ->whereDate('warranty_expires_at', '<', now()->toDateString());
                }
            }

            $units = $query->orderByDesc('purchase_date')
                ->paginate($request->input('per_page', 15));

            return ApiResponseClass::sendPaginatedResponse(
                SoldUnitResource::collection($units),
                $units,
                'Mis unidades',
                200
            );
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al obtener unidades', 500, [$e->getMessage()]);
        }
    }

    /**
     * Registrar una unidad manualmente.
     */
    public function store(Request $request)
    {
        try {
            $client = Auth::guard('client')->user();

            if (!$client) {
                return ApiResponseClass::errorResponse('No autenticado', 401);
            }

            $validator = Validator::make($request->all(), [
                'product_id' => 'required|exists:products,id',
                'serial_number' => 'nullable|string|max:191',
                'purchase_date' => 'required|date|before_or_equal:today',
                'proof_file' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:5120',
            ]);

            if ($validator->fails()) {
                return ApiResponseClass::errorResponse('Error de validación', 422, $validator->errors());
            }

            $unit = $this->soldUnits->registerManual(
                $client,
                $validator->validated(),
                $request->file('proof_file')
            );

            return ApiResponseClass::sendResponse(
                new SoldUnitResource($unit->load('product')),
                'Unidad registrada exitosamente',
                201
            );
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al registrar unidad', 500, [$e->getMessage()]);
        }
    }

    /**
     * Detalle de una unidad del cliente.
     */
    public function show(Request $request, string $id)
    {
        try {
            $client = Auth::guard('client')->user();

            if (!$client) {
                return ApiResponseClass::errorResponse('No autenticado', 401);
            }

            $unit = $client->soldUnits()->with('product')->find($id);

            if (!$unit) {
                return ApiResponseClass::errorResponse('Unidad no encontrada', 404);
            }

            return ApiResponseClass::sendResponse(
                new SoldUnitResource($unit),
                'Detalle de unidad',
                200
            );
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al obtener unidad', 500, [$e->getMessage()]);
        }
    }

    /**
     * Estado de garantía de una unidad.
     */
    public function warranty(Request $request, string $id)
    {
        try {
            $client = Auth::guard('client')->user();

            if (!$client) {
                return ApiResponseClass::errorResponse('No autenticado', 401);
            }

            $unit = $client->soldUnits()->find($id);

            if (!$unit) {
                return ApiResponseClass::errorResponse('Unidad no encontrada', 404);
            }

            return ApiResponseClass::sendResponse(
                (new SoldUnitResource($unit))->warrantyArray(),
                'Garantía de la unidad',
                200
            );
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al obtener garantía', 500, [$e->getMessage()]);
        }
    }
}
