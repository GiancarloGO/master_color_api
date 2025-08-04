<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Client;
use App\Models\Payment;
use App\Models\StockMovement;
use App\Classes\ApiResponseClass;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Get comprehensive dashboard overview
     */
    public function overview()
    {
        try {
            $data = [
                'summary' => $this->getSummaryMetrics(),
                'recent_activity' => $this->getRecentActivity(),
                'alerts' => $this->getAlerts()
            ];

            return ApiResponseClass::sendResponse(
                $data,
                'Dashboard overview',
                200
            );
        } catch (\Exception $e) {
            Log::error('Error fetching dashboard overview: ' . $e->getMessage());
            return ApiResponseClass::errorResponse('Error interno del servidor', 500, [$e->getMessage()]);
        }
    }

    /**
     * Get sales analytics for charts
     */
    public function salesAnalytics(Request $request)
    {
        try {
            $period = $request->input('period', '30'); // days
            $startDate = now()->subDays((int)$period);

            $data = [
                'sales_trend' => $this->getSalesTrend($startDate),
                'revenue_trend' => $this->getRevenueTrend($startDate),
                'orders_by_status' => $this->getOrdersByStatus(),
                'payment_methods' => $this->getPaymentMethodsStats(),
                'top_products' => $this->getTopProducts($startDate),
                'sales_by_category' => $this->getSalesByCategory($startDate),
                'hourly_sales' => $this->getHourlySales($startDate),
                'monthly_comparison' => $this->getMonthlyComparison(),
            ];

            return ApiResponseClass::sendResponse(
                $data,
                'Análisis de ventas',
                200
            );
        } catch (\Exception $e) {
            Log::error('Error fetching sales analytics: ' . $e->getMessage());
            return ApiResponseClass::errorResponse('Error interno del servidor', 500, [$e->getMessage()]);
        }
    }

    /**
     * Get inventory analytics
     */
    public function inventoryAnalytics()
    {
        try {
            $data = [
                'stock_levels' => $this->getStockLevels(),
                'low_stock_products' => $this->getLowStockProducts(),
                'stock_movements' => $this->getStockMovements(),
                'inventory_value' => $this->getInventoryValue(),
                'products_by_category' => $this->getProductsByCategory(),
                'stock_turnover' => $this->getStockTurnover(),
            ];

            return ApiResponseClass::sendResponse(
                $data,
                'Análisis de inventario',
                200
            );
        } catch (\Exception $e) {
            Log::error('Error fetching inventory analytics: ' . $e->getMessage());
            return ApiResponseClass::errorResponse('Error interno del servidor', 500, [$e->getMessage()]);
        }
    }

    /**
     * Get customer analytics
     */
    public function customerAnalytics(Request $request)
    {
        try {
            $period = $request->input('period', '90'); // days
            $startDate = now()->subDays((int)$period);

            $data = [
                'customer_growth' => $this->getCustomerGrowth($startDate),
                'customer_segments' => $this->getCustomerSegments(),
                'top_customers' => $this->getTopCustomers($startDate),
                'customer_lifetime_value' => $this->getCustomerLifetimeValue(),
                'customer_retention' => $this->getCustomerRetention($startDate),
                'geographic_distribution' => $this->getGeographicDistribution(),
            ];

            return ApiResponseClass::sendResponse(
                $data,
                'Análisis de clientes',
                200
            );
        } catch (\Exception $e) {
            Log::error('Error fetching customer analytics: ' . $e->getMessage());
            return ApiResponseClass::errorResponse('Error interno del servidor', 500, [$e->getMessage()]);
        }
    }

    /**
     * Get financial analytics
     */
    public function financialAnalytics(Request $request)
    {
        try {
            $period = $request->input('period', '12'); // months
            $startDate = now()->subMonths((int)$period);

            $data = [
                'revenue_breakdown' => $this->getRevenueBreakdown($startDate),
                'profit_margins' => $this->getProfitMargins($startDate),
                'cash_flow' => $this->getCashFlow($startDate),
                'payment_status' => $this->getPaymentStatus(),
                'financial_summary' => $this->getFinancialSummary($startDate),
            ];

            return ApiResponseClass::sendResponse(
                $data,
                'Análisis financiero',
                200
            );
        } catch (\Exception $e) {
            Log::error('Error fetching financial analytics: ' . $e->getMessage());
            return ApiResponseClass::errorResponse('Error interno del servidor', 500, [$e->getMessage()]);
        }
    }

    /**
     * Get performance metrics
     */
    public function performanceMetrics(Request $request)
    {
        try {
            $period = $request->input('period', '30'); // days
            $startDate = now()->subDays((int)$period);

            $data = [
                'kpis' => $this->getKPIs($startDate),
                'conversion_rates' => $this->getConversionRates($startDate),
                'order_fulfillment' => $this->getOrderFulfillment($startDate),
                'product_performance' => $this->getProductPerformance($startDate),
            ];

            return ApiResponseClass::sendResponse(
                $data,
                'Métricas de rendimiento',
                200
            );
        } catch (\Exception $e) {
            Log::error('Error fetching performance metrics: ' . $e->getMessage());
            return ApiResponseClass::errorResponse('Error interno del servidor', 500, [$e->getMessage()]);
        }
    }

    // Private helper methods

    private function getSummaryMetrics()
    {
        $today = now()->startOfDay();
        $thisWeek = now()->startOfWeek();
        $thisMonth = now()->startOfMonth();

        return [
            'total_orders' => Order::count(),
            'total_customers' => Client::count(),
            'total_products' => Product::count(),
            'total_revenue' => Order::whereNotIn('status', ['cancelado', 'pago_fallido'])->sum('subtotal') ?? 0,
            
            'today' => [
                'orders' => Order::whereDate('created_at', $today)->count(),
                'revenue' => Order::whereDate('created_at', $today)
                    ->whereNotIn('status', ['cancelado', 'pago_fallido'])
                    ->sum('subtotal') ?? 0,
                'new_customers' => Client::whereDate('created_at', $today)->count(),
            ],
            
            'this_week' => [
                'orders' => Order::where('created_at', '>=', $thisWeek)->count(),
                'revenue' => Order::where('created_at', '>=', $thisWeek)
                    ->whereNotIn('status', ['cancelado', 'pago_fallido'])
                    ->sum('subtotal') ?? 0,
                'new_customers' => Client::where('created_at', '>=', $thisWeek)->count(),
            ],
            
            'this_month' => [
                'orders' => Order::where('created_at', '>=', $thisMonth)->count(),
                'revenue' => Order::where('created_at', '>=', $thisMonth)
                    ->whereNotIn('status', ['cancelado', 'pago_fallido'])
                    ->sum('subtotal') ?? 0,
                'new_customers' => Client::where('created_at', '>=', $thisMonth)->count(),
            ]
        ];
    }

    private function getRecentActivity()
    {
        return [
            'recent_orders' => Order::with(['client', 'orderDetails.product'])
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get()
                ->map(function ($order) {
                    return [
                        'id' => $order->id,
                        'client_name' => $order->client->name,
                        'total' => $order->total,
                        'status' => $order->status,
                        'created_at' => $order->created_at,
                        'items_count' => $order->orderDetails->count()
                    ];
                }),
            
            'recent_customers' => Client::orderBy('created_at', 'desc')
                ->limit(5)
                ->get(['id', 'name', 'email', 'created_at']),
                
            'recent_payments' => Payment::with('order.client')
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get()
                ->map(function ($payment) {
                    return [
                        'id' => $payment->id,
                        'order_id' => $payment->order_id,
                        'client_name' => $payment->order->client->name ?? 'N/A',
                        'amount' => $payment->amount,
                        'status' => $payment->status,
                        'payment_method' => $payment->payment_method,
                        'created_at' => $payment->created_at
                    ];
                })
        ];
    }

    private function getAlerts()
    {
        $alerts = [];

        // Low stock alerts
        $lowStockCount = Stock::whereRaw('quantity <= min_stock')->count();
        if ($lowStockCount > 0) {
            $alerts[] = [
                'type' => 'warning',
                'message' => "Hay {$lowStockCount} productos con stock bajo",
                'action' => '/inventory/low-stock',
                'priority' => 'high'
            ];
        }

        // Pending orders
        $pendingOrders = Order::whereIn('status', ['pendiente_pago', 'pendiente'])->count();
        if ($pendingOrders > 10) {
            $alerts[] = [
                'type' => 'info',
                'message' => "Hay {$pendingOrders} órdenes pendientes",
                'action' => '/orders?status=pending',
                'priority' => 'medium'
            ];
        }

        // Failed payments
        $failedPayments = Order::where('status', 'pago_fallido')
            ->whereDate('created_at', '>', now()->subDays(7))
            ->count();
        if ($failedPayments > 0) {
            $alerts[] = [
                'type' => 'error',
                'message' => "{$failedPayments} pagos fallidos en los últimos 7 días",
                'action' => '/orders?status=pago_fallido',
                'priority' => 'high'
            ];
        }

        return $alerts;
    }

    private function getSalesTrend($startDate)
    {
        return Order::select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as orders'),
                DB::raw('SUM(CASE WHEN status NOT IN (\'cancelado\', \'pago_fallido\') THEN subtotal ELSE 0 END) as revenue')
            )
            ->where('created_at', '>=', $startDate)
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();
    }

    private function getRevenueTrend($startDate)
    {
        return Order::select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(subtotal) as gross_revenue'),
                DB::raw('SUM(CASE WHEN status NOT IN (\'cancelado\', \'pago_fallido\') THEN subtotal ELSE 0 END) as net_revenue'),
                DB::raw('COUNT(*) as total_orders'),
                DB::raw('COUNT(CASE WHEN status NOT IN (\'cancelado\', \'pago_fallido\') THEN 1 END) as successful_orders')
            )
            ->where('created_at', '>=', $startDate)
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();
    }

    private function getOrdersByStatus()
    {
        return Order::select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->status => $item->count];
            });
    }

    private function getPaymentMethodsStats()
    {
        return Payment::select('payment_method', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as total'))
            ->groupBy('payment_method')
            ->get();
    }

    private function getTopProducts($startDate)
    {
        return OrderDetail::select(
                'products.name',
                'products.id',
                DB::raw('SUM(order_details.quantity) as total_quantity'),
                DB::raw('SUM(order_details.subtotal) as total_revenue'),
                DB::raw('COUNT(DISTINCT order_details.order_id) as times_ordered')
            )
            ->join('products', 'order_details.product_id', '=', 'products.id')
            ->join('orders', 'order_details.order_id', '=', 'orders.id')
            ->where('orders.created_at', '>=', $startDate)
            ->whereNotIn('orders.status', ['cancelado', 'pago_fallido'])
            ->groupBy('products.id', 'products.name')
            ->orderBy('total_revenue', 'desc')
            ->limit(10)
            ->get();
    }

    private function getSalesByCategory($startDate)
    {
        return OrderDetail::select(
                'products.category',
                DB::raw('SUM(order_details.quantity) as total_quantity'),
                DB::raw('SUM(order_details.subtotal) as total_revenue'),
                DB::raw('COUNT(DISTINCT order_details.order_id) as orders_count')
            )
            ->join('products', 'order_details.product_id', '=', 'products.id')
            ->join('orders', 'order_details.order_id', '=', 'orders.id')
            ->where('orders.created_at', '>=', $startDate)
            ->whereNotIn('orders.status', ['cancelado', 'pago_fallido'])
            ->groupBy('products.category')
            ->orderBy('total_revenue', 'desc')
            ->get();
    }

    private function getHourlySales($startDate)
    {
        return Order::select(
                DB::raw('EXTRACT(HOUR FROM created_at) as hour'),
                DB::raw('COUNT(*) as orders'),
                DB::raw('SUM(CASE WHEN status NOT IN (\'cancelado\', \'pago_fallido\') THEN subtotal ELSE 0 END) as revenue')
            )
            ->where('created_at', '>=', $startDate)
            ->groupBy(DB::raw('EXTRACT(HOUR FROM created_at)'))
            ->orderBy('hour')
            ->get();
    }

    private function getMonthlyComparison()
    {
        $currentMonth = now()->startOfMonth();
        $previousMonth = now()->subMonth()->startOfMonth();
        
        $current = Order::where('created_at', '>=', $currentMonth)
            ->whereNotIn('status', ['cancelado', 'pago_fallido'])
            ->selectRaw('COUNT(*) as orders, SUM(subtotal) as revenue')
            ->first();
            
        $previous = Order::whereBetween('created_at', [$previousMonth, $currentMonth])
            ->whereNotIn('status', ['cancelado', 'pago_fallido'])
            ->selectRaw('COUNT(*) as orders, SUM(subtotal) as revenue')
            ->first();

        return [
            'current_month' => $current,
            'previous_month' => $previous,
            'growth' => [
                'orders' => $previous->orders > 0 ? (($current->orders - $previous->orders) / $previous->orders) * 100 : 0,
                'revenue' => $previous->revenue > 0 ? (($current->revenue - $previous->revenue) / $previous->revenue) * 100 : 0,
            ]
        ];
    }

    private function getStockLevels()
    {
        return [
            'total_products' => Stock::count(),
            'in_stock' => Stock::where('quantity', '>', 0)->count(),
            'out_of_stock' => Stock::where('quantity', 0)->count(),
            'low_stock' => Stock::whereRaw('quantity <= min_stock')->count(),
            'overstock' => Stock::whereRaw('quantity > max_stock')->count(),
        ];
    }

    private function getLowStockProducts()
    {
        return Stock::with('product')
            ->whereRaw('quantity <= min_stock')
            ->orderBy('quantity', 'asc')
            ->limit(10)
            ->get()
            ->map(function ($stock) {
                return [
                    'id' => $stock->product->id,
                    'name' => $stock->product->name,
                    'sku' => $stock->product->sku,
                    'current_stock' => $stock->quantity,
                    'min_stock' => $stock->min_stock,
                    'category' => $stock->product->category,
                ];
            });
    }

    private function getStockMovements()
    {
        return StockMovement::select(
                'movement_type',
                DB::raw('COUNT(*) as count'),
                DB::raw('DATE(created_at) as date')
            )
            ->where('created_at', '>=', now()->subDays(30))
            ->whereNull('canceled_at')
            ->groupBy('movement_type', DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();
    }

    private function getInventoryValue()
    {
        return Stock::join('products', 'stocks.product_id', '=', 'products.id')
            ->selectRaw('
                SUM(stocks.quantity * stocks.purchase_price) as total_purchase_value,
                SUM(stocks.quantity * stocks.sale_price) as total_sale_value,
                SUM(stocks.quantity) as total_quantity,
                AVG(stocks.sale_price) as avg_sale_price
            ')
            ->first();
    }

    private function getProductsByCategory()
    {
        return Product::select('category', DB::raw('COUNT(*) as count'))
            ->groupBy('category')
            ->get();
    }

    private function getStockTurnover()
    {
        // Simplified stock turnover calculation
        return OrderDetail::select(
                'products.name',
                'products.category',
                DB::raw('SUM(order_details.quantity) as total_sold'),
                DB::raw('AVG(stocks.quantity) as avg_stock'),
                DB::raw('CASE WHEN AVG(stocks.quantity) > 0 THEN SUM(order_details.quantity) / AVG(stocks.quantity) ELSE 0 END as turnover_ratio')
            )
            ->join('products', 'order_details.product_id', '=', 'products.id')
            ->join('stocks', 'products.id', '=', 'stocks.product_id')
            ->join('orders', 'order_details.order_id', '=', 'orders.id')
            ->where('orders.created_at', '>=', now()->subDays(90))
            ->whereNotIn('orders.status', ['cancelado', 'pago_fallido'])
            ->groupBy('products.id', 'products.name', 'products.category')
            ->orderBy('turnover_ratio', 'desc')
            ->limit(10)
            ->get();
    }

    private function getCustomerGrowth($startDate)
    {
        return Client::select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as new_customers')
            )
            ->where('created_at', '>=', $startDate)
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();
    }

    private function getCustomerSegments()
    {
        return Client::select('client_type', DB::raw('COUNT(*) as count'))
            ->groupBy('client_type')
            ->get();
    }

    private function getTopCustomers($startDate)
    {
        return Client::select(
                'clients.id',
                'clients.name',
                'clients.email',
                DB::raw('COUNT(orders.id) as total_orders'),
                DB::raw('SUM(CASE WHEN orders.status NOT IN (\'cancelado\', \'pago_fallido\') THEN orders.subtotal ELSE 0 END) as total_spent')
            )
            ->leftJoin('orders', 'clients.id', '=', 'orders.client_id')
            ->where('orders.created_at', '>=', $startDate)
            ->groupBy('clients.id', 'clients.name', 'clients.email')
            ->havingRaw('COUNT(orders.id) > 0')
            ->orderBy('total_spent', 'desc')
            ->limit(10)
            ->get();
    }

    private function getCustomerLifetimeValue()
    {
        $result = DB::select("
            SELECT 
                AVG(customer_stats.total_spent) as avg_ltv,
                AVG(customer_stats.total_orders) as avg_orders,
                AVG(CASE WHEN customer_stats.total_orders > 0 THEN customer_stats.total_spent / customer_stats.total_orders ELSE 0 END) as avg_order_value
            FROM (
                SELECT 
                    clients.id,
                    COUNT(orders.id) as total_orders,
                    SUM(CASE WHEN orders.status NOT IN ('cancelado', 'pago_fallido') THEN orders.subtotal ELSE 0 END) as total_spent
                FROM clients
                LEFT JOIN orders ON clients.id = orders.client_id
                WHERE clients.deleted_at IS NULL
                GROUP BY clients.id
                HAVING COUNT(orders.id) > 0
            ) as customer_stats
        ");
        
        return $result[0] ?? (object) ['avg_ltv' => 0, 'avg_orders' => 0, 'avg_order_value' => 0];
    }

    private function getCustomerRetention($startDate)
    {
        $totalCustomers = Client::where('created_at', '<', $startDate)->count();
        $returningCustomers = Client::where('created_at', '<', $startDate)
            ->whereHas('orders', function ($query) use ($startDate) {
                $query->where('created_at', '>=', $startDate);
            })
            ->count();

        return [
            'total_customers' => $totalCustomers,
            'returning_customers' => $returningCustomers,
            'retention_rate' => $totalCustomers > 0 ? ($returningCustomers / $totalCustomers) * 100 : 0
        ];
    }

    private function getGeographicDistribution()
    {
        return Order::join('addresses', 'orders.delivery_address_id', '=', 'addresses.id')
            ->select(
                'addresses.department',
                'addresses.province', 
                'addresses.district',
                DB::raw('COUNT(*) as orders_count')
            )
            ->groupBy('addresses.department', 'addresses.province', 'addresses.district')
            ->orderBy('orders_count', 'desc')
            ->limit(15)
            ->get()
            ->map(function ($item) {
                return [
                    'location' => $item->district . ', ' . $item->province . ', ' . $item->department,
                    'district' => $item->district,
                    'province' => $item->province,
                    'department' => $item->department,
                    'orders_count' => $item->orders_count
                ];
            });
    }

    private function getRevenueBreakdown($startDate)
    {
        return Order::select(
                DB::raw('EXTRACT(YEAR FROM created_at) as year'),
                DB::raw('EXTRACT(MONTH FROM created_at) as month'),
                DB::raw('SUM(subtotal) as gross_revenue'),
                DB::raw('SUM(shipping_cost) as shipping_revenue'),
                DB::raw('SUM(discount) as total_discounts'),
                DB::raw('SUM(CASE WHEN status NOT IN (\'cancelado\', \'pago_fallido\') THEN subtotal ELSE 0 END) as net_revenue')
            )
            ->where('created_at', '>=', $startDate)
            ->groupBy(DB::raw('EXTRACT(YEAR FROM created_at)'), DB::raw('EXTRACT(MONTH FROM created_at)'))
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->get();
    }

    private function getProfitMargins($startDate)
    {
        return OrderDetail::select(
                'products.category',
                DB::raw('SUM(order_details.subtotal) as revenue'),
                DB::raw('SUM(order_details.quantity * stocks.purchase_price) as cost'),
                DB::raw('SUM(order_details.subtotal) - SUM(order_details.quantity * stocks.purchase_price) as profit'),
                DB::raw('CASE WHEN SUM(order_details.subtotal) > 0 THEN ((SUM(order_details.subtotal) - SUM(order_details.quantity * stocks.purchase_price)) / SUM(order_details.subtotal)) * 100 ELSE 0 END as margin_percentage')
            )
            ->join('products', 'order_details.product_id', '=', 'products.id')
            ->join('stocks', 'products.id', '=', 'stocks.product_id')
            ->join('orders', 'order_details.order_id', '=', 'orders.id')
            ->where('orders.created_at', '>=', $startDate)
            ->whereNotIn('orders.status', ['cancelado', 'pago_fallido'])
            ->groupBy('products.category')
            ->havingRaw('SUM(order_details.subtotal) > 0')
            ->get();
    }

    private function getCashFlow($startDate)
    {
        return Payment::select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(CASE WHEN status = \'approved\' THEN amount ELSE 0 END) as cash_in'),
                DB::raw('SUM(CASE WHEN status = \'refunded\' THEN amount ELSE 0 END) as cash_out'),
                DB::raw('SUM(CASE WHEN status = \'approved\' THEN amount ELSE 0 END) - SUM(CASE WHEN status = \'refunded\' THEN amount ELSE 0 END) as net_cash_flow')
            )
            ->where('created_at', '>=', $startDate)
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();
    }

    private function getPaymentStatus()
    {
        return Payment::select('status', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as total'))
            ->groupBy('status')
            ->get();
    }

    private function getFinancialSummary($startDate)
    {
        return [
            'gross_revenue' => Order::where('created_at', '>=', $startDate)->sum('subtotal') ?? 0,
            'net_revenue' => Order::where('created_at', '>=', $startDate)
                ->whereNotIn('status', ['cancelado', 'pago_fallido'])->sum('subtotal') ?? 0,
            'total_payments' => Payment::where('created_at', '>=', $startDate)
                ->where('status', 'approved')->sum('amount') ?? 0,
            'pending_payments' => Payment::where('created_at', '>=', $startDate)
                ->where('status', 'pending')->sum('amount') ?? 0,
            'refunds' => Payment::where('created_at', '>=', $startDate)
                ->where('status', 'refunded')->sum('amount') ?? 0,
            'average_order_value' => Order::where('created_at', '>=', $startDate)
                ->whereNotIn('status', ['cancelado', 'pago_fallido'])->avg('subtotal') ?? 0,
        ];
    }

    private function getKPIs($startDate)
    {
        $totalOrders = Order::where('created_at', '>=', $startDate)->count();
        $successfulOrders = Order::where('created_at', '>=', $startDate)
            ->whereNotIn('status', ['cancelado', 'pago_fallido'])->count();

        return [
            'conversion_rate' => $totalOrders > 0 ? ($successfulOrders / $totalOrders) * 100 : 0,
            'average_order_value' => Order::where('created_at', '>=', $startDate)
                ->whereNotIn('status', ['cancelado', 'pago_fallido'])
                ->avg('subtotal') ?? 0,
            'customer_acquisition_cost' => 0, // Would need marketing spend data
            'order_fulfillment_rate' => $successfulOrders > 0 ? 
                (Order::where('created_at', '>=', $startDate)->where('status', 'entregado')->count() / $successfulOrders) * 100 : 0,
            'return_rate' => 0, // Would need return data
        ];
    }

    private function getConversionRates($startDate)
    {
        $totalOrders = Order::where('created_at', '>=', $startDate)->count();
        $pendingPayment = Order::where('created_at', '>=', $startDate)->where('status', 'pendiente_pago')->count();
        $successful = Order::where('created_at', '>=', $startDate)
            ->whereNotIn('status', ['cancelado', 'pago_fallido', 'pendiente_pago'])->count();

        return [
            'order_to_payment' => $totalOrders > 0 ? (($totalOrders - $pendingPayment) / $totalOrders) * 100 : 0,
            'payment_to_success' => $totalOrders > 0 ? ($successful / $totalOrders) * 100 : 0,
            'abandonment_rate' => $totalOrders > 0 ? ($pendingPayment / $totalOrders) * 100 : 0,
        ];
    }

    private function getOrderFulfillment($startDate)
    {
        $statuses = ['pendiente', 'confirmado', 'procesando', 'enviado', 'entregado'];
        $fulfillment = [];

        foreach ($statuses as $status) {
            $count = Order::where('created_at', '>=', $startDate)->where('status', $status)->count();
            $fulfillment[$status] = $count;
        }

        return $fulfillment;
    }

    private function getProductPerformance($startDate)
    {
        return Product::select(
                'products.id',
                'products.name',
                'products.category',
                DB::raw('COALESCE(SUM(order_details.quantity), 0) as units_sold'),
                DB::raw('COALESCE(SUM(order_details.subtotal), 0) as revenue'),
                DB::raw('stocks.quantity as current_stock'),
                DB::raw('CASE WHEN stocks.quantity > 0 THEN COALESCE(SUM(order_details.quantity), 0)::float / stocks.quantity ELSE 0 END as turnover_rate')
            )
            ->leftJoin('order_details', 'products.id', '=', 'order_details.product_id')
            ->leftJoin('orders', function ($join) use ($startDate) {
                $join->on('order_details.order_id', '=', 'orders.id')
                     ->where('orders.created_at', '>=', $startDate)
                     ->whereNotIn('orders.status', ['cancelado', 'pago_fallido']);
            })
            ->leftJoin('stocks', 'products.id', '=', 'stocks.product_id')
            ->groupBy('products.id', 'products.name', 'products.category', 'stocks.quantity')
            ->orderBy('revenue', 'desc')
            ->limit(20)
            ->get();
    }
}