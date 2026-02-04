<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Models\Order;
use App\Models\Client;
use App\Http\Resources\OrderResource;
use App\Classes\ApiResponseClass;
use App\Events\OrderStatusChanged;
use Illuminate\Support\Facades\Auth;

class OrderController extends Controller
{
    /**
     * Display a listing of all orders for staff management.
     */
    public function index(Request $request)
    {
        try {
            $query = Order::with(['client', 'orderDetails.product', 'deliveryAddress']);
            
            // Filter by status if provided
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }
            
            // Filter by client if provided
            if ($request->has('client_id')) {
                $query->where('client_id', $request->client_id);
            }
            
            // Filter by date range
            if ($request->has('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }
            
            if ($request->has('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }
            
            // Order by most recent first
            $query->orderBy('created_at', 'desc');
            
            // Check if pagination is requested
            $paginate = $request->query('paginate', 'false');
            $paginate = filter_var($paginate, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            
            if ($paginate === true) {
                $perPage = $request->input('per_page', 15);
                $orders = $query->paginate($perPage);
            } else {
                $orders = $query->get();
            }
            
            return ApiResponseClass::sendResponse(
                OrderResource::collection($orders),
                'Lista de pedidos',
                200
            );
        } catch (\Exception $e) {
            Log::error('Error fetching orders: ' . $e->getMessage());
            return ApiResponseClass::errorResponse('Error interno del servidor', 500, [$e->getMessage()]);
        }
    }

    /**
     * Display the specified order.
     */
    public function show(Request $request, string $id)
    {
        try {
            $order = Order::with([
                'client', 
                'orderDetails.product', 
                'deliveryAddress',
                'user',
                'payments'
            ])->find($id);
            
            if (!$order) {
                return ApiResponseClass::errorResponse('Pedido no encontrado', 404);
            }
            
            return ApiResponseClass::sendResponse(
                new OrderResource($order),
                'Detalle de pedido',
                200
            );
        } catch (\Exception $e) {
            Log::error('Error fetching order: ' . $e->getMessage());
            return ApiResponseClass::errorResponse('Error interno del servidor', 500, [$e->getMessage()]);
        }
    }

    /**
     * Update order status.
     */
    public function updateStatus(Request $request, string $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'status' => 'required|string|in:pendiente_pago,pendiente,confirmado,procesando,enviado,entregado,cancelado,pago_fallido',
                'observations' => 'nullable|string|max:500'
            ]);
            
            if ($validator->fails()) {
                return ApiResponseClass::errorResponse('Error de validación', 422, $validator->errors());
            }
            
            $order = Order::find($id);
            
            if (!$order) {
                return ApiResponseClass::errorResponse('Pedido no encontrado', 404);
            }
            
            // Validate status transitions
            $currentStatus = $order->status;
            $newStatus = $request->status;
            
            if (!$this->isValidStatusTransition($currentStatus, $newStatus)) {
                return ApiResponseClass::errorResponse(
                    "No se puede cambiar el estado de '{$currentStatus}' a '{$newStatus}'",
                    422
                );
            }
            
            $order->status = $newStatus;
            $order->user_id = Auth::id(); // Track who updated the order
            
            if ($request->has('observations')) {
                $order->observations = $request->observations;
            }
            
            $order->save();

            // Dispatch event for email notification if status actually changed
            if ($currentStatus !== $newStatus) {
                event(new OrderStatusChanged($order, $currentStatus, $newStatus));
            }
            
            return ApiResponseClass::sendResponse(
                new OrderResource($order->load(['client', 'orderDetails.product', 'deliveryAddress'])),
                'Estado de pedido actualizado exitosamente',
                200
            );
        } catch (\Exception $e) {
            Log::error('Error updating order status: ' . $e->getMessage());
            return ApiResponseClass::errorResponse('Error interno del servidor', 500, [$e->getMessage()]);
        }
    }

    /**
     * Get order statistics for dashboard.
     */
    public function getStatistics()
    {
        try {
            $stats = [
                'total_orders' => Order::count(),
                'pending_orders' => Order::whereIn('status', ['pendiente_pago', 'pendiente'])->count(),
                'pending_payment_orders' => Order::where('status', 'pendiente_pago')->count(),
                'pending_confirmation_orders' => Order::where('status', 'pendiente')->count(),
                'confirmed_orders' => Order::where('status', 'confirmado')->count(),
                'processing_orders' => Order::where('status', 'procesando')->count(),
                'shipped_orders' => Order::where('status', 'enviado')->count(),
                'delivered_orders' => Order::where('status', 'entregado')->count(),
                'cancelled_orders' => Order::where('status', 'cancelado')->count(),
                'failed_payment_orders' => Order::where('status', 'pago_fallido')->count(),
                'today_orders' => Order::whereDate('created_at', today())->count(),
                'this_week_orders' => Order::whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])->count(),
                'this_month_orders' => Order::whereMonth('created_at', now()->month)
                                           ->whereYear('created_at', now()->year)
                                           ->count(),
                'total_revenue' => Order::whereNotIn('status', ['cancelado', 'pago_fallido'])
                                       ->sum('subtotal'),
                'pending_revenue' => Order::whereIn('status', ['pendiente_pago', 'pendiente'])
                                         ->sum('subtotal')
            ];
            
            return ApiResponseClass::sendResponse(
                $stats,
                'Estadísticas de pedidos',
                200
            );
        } catch (\Exception $e) {
            Log::error('Error fetching order statistics: ' . $e->getMessage());
            return ApiResponseClass::errorResponse('Error interno del servidor', 500, [$e->getMessage()]);
        }
    }

    /**
     * Get orders by status for quick management.
     */
    public function getByStatus(Request $request, string $status)
    {
        try {
            $validStatuses = ['pendiente_pago', 'pendiente', 'confirmado', 'procesando', 'enviado', 'entregado', 'cancelado', 'pago_fallido'];
            
            if (!in_array($status, $validStatuses)) {
                return ApiResponseClass::errorResponse('Estado no válido', 422);
            }
            
            $query = Order::with(['client', 'orderDetails.product', 'deliveryAddress'])
                         ->where('status', $status)
                         ->orderBy('created_at', 'desc');
            
            // Check if pagination is requested
            $paginate = $request->query('paginate', 'false');
            $paginate = filter_var($paginate, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            
            if ($paginate === true) {
                $perPage = $request->input('per_page', 15);
                $orders = $query->paginate($perPage);
            } else {
                $orders = $query->get();
            }
            
            return ApiResponseClass::sendResponse(
                OrderResource::collection($orders),
                "Pedidos con estado: {$status}",
                200
            );
        } catch (\Exception $e) {
            Log::error('Error fetching orders by status: ' . $e->getMessage());
            return ApiResponseClass::errorResponse('Error interno del servidor', 500, [$e->getMessage()]);
        }
    }

    /**
     * Search orders by various criteria.
     */
    public function search(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'search' => 'required|string|min:1',
                'search_type' => 'nullable|string|in:order_id,client_name,client_email,all'
            ]);
            
            if ($validator->fails()) {
                return ApiResponseClass::errorResponse('Error de validación', 422, $validator->errors());
            }
            
            $searchTerm = $request->search;
            $searchType = $request->input('search_type', 'all');
            
            $query = Order::with(['client', 'orderDetails.product', 'deliveryAddress']);
            
            switch ($searchType) {
                case 'order_id':
                    $query->where('id', 'like', "%{$searchTerm}%");
                    break;
                case 'client_name':
                    $query->whereHas('client', function ($q) use ($searchTerm) {
                        $q->where('name', 'like', "%{$searchTerm}%")
                          ->orWhere('lastname', 'like', "%{$searchTerm}%");
                    });
                    break;
                case 'client_email':
                    $query->whereHas('client', function ($q) use ($searchTerm) {
                        $q->where('email', 'like', "%{$searchTerm}%");
                    });
                    break;
                default: // 'all'
                    $query->where(function ($q) use ($searchTerm) {
                        $q->where('id', 'like', "%{$searchTerm}%")
                          ->orWhereHas('client', function ($clientQuery) use ($searchTerm) {
                              $clientQuery->where('name', 'like', "%{$searchTerm}%")
                                         ->orWhere('lastname', 'like', "%{$searchTerm}%")
                                         ->orWhere('email', 'like', "%{$searchTerm}%");
                          });
                    });
                    break;
            }
            
            $query->orderBy('created_at', 'desc');
            
            // Check if pagination is requested
            $paginate = $request->query('paginate', 'false');
            $paginate = filter_var($paginate, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            
            if ($paginate === true) {
                $perPage = $request->input('per_page', 15);
                $orders = $query->paginate($perPage);
            } else {
                $orders = $query->get();
            }
            
            return ApiResponseClass::sendResponse(
                OrderResource::collection($orders),
                'Resultados de búsqueda',
                200
            );
        } catch (\Exception $e) {
            Log::error('Error searching orders: ' . $e->getMessage());
            return ApiResponseClass::errorResponse('Error interno del servidor', 500, [$e->getMessage()]);
        }
    }

    /**
     * Validate if a status transition is allowed.
     */
    private function isValidStatusTransition(string $currentStatus, string $newStatus): bool
    {
        $allowedTransitions = [
            'pendiente_pago' => ['pendiente', 'cancelado', 'pago_fallido'],
            'pendiente' => ['confirmado', 'cancelado'],
            'confirmado' => ['procesando', 'cancelado'],
            'procesando' => ['enviado', 'cancelado'],
            'enviado' => ['entregado'],
            'entregado' => [], // Final state
            'cancelado' => [], // Final state
            'pago_fallido' => ['pendiente_pago', 'cancelado']
        ];
        
        // Allow same status (no change)
        if ($currentStatus === $newStatus) {
            return true;
        }
        
        return in_array($newStatus, $allowedTransitions[$currentStatus] ?? []);
    }

    /**
     * Store method - Not implemented for staff (orders are created by clients)
     */
    public function store(Request $request)
    {
        return ApiResponseClass::errorResponse(
            'Los pedidos son creados por los clientes. Use el endpoint de cliente para crear pedidos.',
            405
        );
    }

    /**
     * Update method - Use updateStatus instead
     */
    public function update(Request $request, string $id)
    {
        return ApiResponseClass::errorResponse(
            'Use el endpoint /orders/{id}/status para actualizar el estado del pedido.',
            405
        );
    }

    /**
     * Delete method - Orders should not be deleted, only cancelled
     */
    public function destroy(string $id)
    {
        return ApiResponseClass::errorResponse(
            'Los pedidos no pueden ser eliminados. Use cancelar en su lugar.',
            405
        );
    }
}
