<?php

namespace App\Http\Controllers;

use App\Classes\ApiResponseClass;
use App\Http\Resources\TicketPartResource;
use App\Models\Stock;
use App\Models\SupportTicket;
use App\Models\TicketPart;
use App\Services\TicketPartService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class SupportPartController extends Controller
{
    public function __construct(private TicketPartService $parts) {}

    /**
     * Buscar repuestos del inventario para registrar consumo en un ticket.
     */
    public function index(Request $request)
    {
        try {
            $query = Stock::with('product');

            if ($request->filled('search')) {
                $term = $request->input('search');
                $query->whereHas('product', function ($q) use ($term) {
                    $q->where('name', 'like', "%{$term}%")
                      ->orWhere('sku', 'like', "%{$term}%");
                });
            }

            $stocks = $query->paginate($request->input('per_page', 15));

            $data = $stocks->getCollection()->map(fn (Stock $s) => [
                'stock_id' => $s->id,
                'product_id' => $s->product_id,
                'product_name' => optional($s->product)->name,
                'sku' => optional($s->product)->sku,
                'available_qty' => $s->quantity,
                'purchase_price' => (float) $s->purchase_price,
            ]);

            return ApiResponseClass::sendPaginatedResponse($data, $stocks, 'Repuestos disponibles', 200);
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al buscar repuestos', 500, [$e->getMessage()]);
        }
    }

    /**
     * Registrar un repuesto consumido por el ticket (descuenta stock).
     */
    public function store(Request $request, string $ticketId)
    {
        try {
            $ticket = SupportTicket::find($ticketId);
            if (!$ticket) {
                return ApiResponseClass::errorResponse('Ticket no encontrado', 404);
            }

            $validator = Validator::make($request->all(), [
                'stock_id' => 'required|integer|exists:stocks,id',
                'quantity' => 'required|integer|min:1',
                'unit_cost' => 'nullable|numeric|min:0|max:99999999.99',
            ]);
            if ($validator->fails()) {
                return ApiResponseClass::errorResponse('Error de validación', 422, $validator->errors());
            }

            $part = $this->parts->addPart(
                $ticket,
                (int) $request->stock_id,
                (int) $request->quantity,
                $request->filled('unit_cost') ? (float) $request->unit_cost : null,
                Auth::user()
            );

            return ApiResponseClass::sendResponse(
                new TicketPartResource($part),
                'Repuesto registrado',
                201
            );
        } catch (DomainException $e) {
            return ApiResponseClass::errorResponse($e->getMessage(), 409);
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al registrar repuesto', 500, [$e->getMessage()]);
        }
    }

    /**
     * Quitar un repuesto del ticket (revierte el descuento de stock).
     */
    public function destroy(string $ticketId, string $partId)
    {
        try {
            $part = TicketPart::where('ticket_id', $ticketId)->find($partId);
            if (!$part) {
                return ApiResponseClass::errorResponse('Repuesto no encontrado', 404);
            }

            $this->parts->removePart($part, Auth::user());

            return ApiResponseClass::sendResponse(null, 'Repuesto eliminado', 200);
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al eliminar repuesto', 500, [$e->getMessage()]);
        }
    }
}
