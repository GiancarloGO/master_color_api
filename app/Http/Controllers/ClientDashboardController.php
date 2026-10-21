<?php

namespace App\Http\Controllers;

use App\Classes\ApiResponseClass;
use App\Services\ClientDashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ClientDashboardController extends Controller
{
    public function __construct(private ClientDashboardService $dashboardService) {}

    /**
     * Resumen consolidado para el home del cliente (Móvil).
     */
    public function summary(Request $request)
    {
        try {
            $client = Auth::guard('client')->user();
            if (!$client) {
                return ApiResponseClass::errorResponse('Cliente no autenticado', 401);
            }

            $data = $this->dashboardService->getSummary($client);

            return ApiResponseClass::sendResponse($data, 'Resumen de inicio del cliente', 200);
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al obtener resumen de inicio', 500, [$e->getMessage()]);
        }
    }
}
