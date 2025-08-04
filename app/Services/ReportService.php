<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Client;
use App\Models\User;
use App\Exports\SalesExport;
use App\Exports\PurchasesExport;
use App\Exports\OrdersExport;
use Maatwebsite\Excel\Facades\Excel;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\View;
use Carbon\Carbon;

class ReportService
{
    /**
     * Get filtered orders query
     */
    private function getFilteredOrders(array $filters)
    {
        $query = Order::with(['client', 'user', 'orderDetails.product'])
            ->whereNotNull('id'); // Base condition

        if (!empty($filters['start_date'])) {
            $query->whereDate('created_at', '>=', $filters['start_date']);
        }

        if (!empty($filters['end_date'])) {
            $query->whereDate('created_at', '<=', $filters['end_date']);
        }

        if (!empty($filters['client_id'])) {
            $query->where('client_id', $filters['client_id']);
        }

        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Generate Sales PDF Report
     */
    public function generateSalesPDF(array $filters)
    {
        $orders = $this->getFilteredOrders($filters)->get();
        
        $data = [
            'orders' => $orders,
            'filters' => $filters,
            'total_orders' => $orders->count(),
            'total_amount' => $orders->sum('subtotal'),
            'generated_at' => Carbon::now()->format('d/m/Y H:i:s'),
            'title' => 'Reporte de Ventas'
        ];

        $html = View::make('reports.sales-pdf', $data)->render();
        
        return $this->generatePDF($html, 'reporte-ventas-' . date('Y-m-d') . '.pdf');
    }

    /**
     * Generate Purchases PDF Report
     */
    public function generatePurchasesPDF(array $filters)
    {
        $orders = $this->getFilteredOrders($filters)->get();
        
        $data = [
            'orders' => $orders,
            'filters' => $filters,
            'total_orders' => $orders->count(),
            'total_amount' => $orders->sum('subtotal'),
            'generated_at' => Carbon::now()->format('d/m/Y H:i:s'),
            'title' => 'Reporte de Compras'
        ];

        $html = View::make('reports.purchases-pdf', $data)->render();
        
        return $this->generatePDF($html, 'reporte-compras-' . date('Y-m-d') . '.pdf');
    }

    /**
     * Generate Orders PDF Report
     */
    public function generateOrdersPDF(array $filters)
    {
        $orders = $this->getFilteredOrders($filters)->get();
        
        $data = [
            'orders' => $orders,
            'filters' => $filters,
            'total_orders' => $orders->count(),
            'total_amount' => $orders->sum('subtotal'),
            'generated_at' => Carbon::now()->format('d/m/Y H:i:s'),
            'title' => 'Reporte de Órdenes'
        ];

        $html = View::make('reports.orders-pdf', $data)->render();
        
        return $this->generatePDF($html, 'reporte-ordenes-' . date('Y-m-d') . '.pdf');
    }

    /**
     * Generate Sales Excel Report
     */
    public function generateSalesExcel(array $filters)
    {
        $orders = $this->getFilteredOrders($filters)->get();
        
        return Excel::download(
            new SalesExport($orders, $filters), 
            'reporte-ventas-' . date('Y-m-d') . '.xlsx'
        );
    }

    /**
     * Generate Purchases Excel Report
     */
    public function generatePurchasesExcel(array $filters)
    {
        $orders = $this->getFilteredOrders($filters)->get();
        
        return Excel::download(
            new PurchasesExport($orders, $filters), 
            'reporte-compras-' . date('Y-m-d') . '.xlsx'
        );
    }

    /**
     * Generate Orders Excel Report
     */
    public function generateOrdersExcel(array $filters)
    {
        $orders = $this->getFilteredOrders($filters)->get();
        
        return Excel::download(
            new OrdersExport($orders, $filters), 
            'reporte-ordenes-' . date('Y-m-d') . '.xlsx'
        );
    }

    /**
     * Generate PDF from HTML
     */
    private function generatePDF(string $html, string $filename)
    {
        $options = new Options();
        $options->set('defaultFont', 'Arial');
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        
        return response($dompdf->output(), 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }
}