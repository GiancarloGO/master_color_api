<?php

namespace App\Http\Controllers;

use App\Classes\ApiResponseClass;
use App\Http\Requests\ReportFilterRequest;
use App\Services\ReportService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    protected $reportService;

    public function __construct(ReportService $reportService)
    {
        $this->reportService = $reportService;
    }

    /**
     * Generate sales report
     */
    public function salesReport(ReportFilterRequest $request)
    {
        try {
            $filters = $request->validated();
            $format = $filters['format'];
            
            if ($format === 'pdf') {
                return $this->reportService->generateSalesPDF($filters);
            } else {
                return $this->reportService->generateSalesExcel($filters);
            }
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error generando reporte de ventas', 500, [$e->getMessage()]);
        }
    }

    /**
     * Generate purchases report (orders from client perspective)
     */
    public function purchasesReport(ReportFilterRequest $request)
    {
        try {
            $filters = $request->validated();
            $format = $filters['format'];
            
            if ($format === 'pdf') {
                return $this->reportService->generatePurchasesPDF($filters);
            } else {
                return $this->reportService->generatePurchasesExcel($filters);
            }
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error generando reporte de compras', 500, [$e->getMessage()]);
        }
    }

    /**
     * Generate orders summary report
     */
    public function ordersReport(ReportFilterRequest $request)
    {
        try {
            $filters = $request->validated();
            $format = $filters['format'];
            
            if ($format === 'pdf') {
                return $this->reportService->generateOrdersPDF($filters);
            } else {
                return $this->reportService->generateOrdersExcel($filters);
            }
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error generando reporte de órdenes', 500, [$e->getMessage()]);
        }
    }
}