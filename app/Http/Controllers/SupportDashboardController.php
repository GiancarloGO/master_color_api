<?php

namespace App\Http\Controllers;

use App\Classes\ApiResponseClass;
use App\Services\SupportDashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SupportDashboardController extends Controller
{
    public function __construct(private SupportDashboardService $dashboardService) {}

    /**
     * Dashboard para Administrador (Métricas globales, gráficos, alertas y últimos tickets).
     */
    public function admin(Request $request)
    {
        try {
            $month = $request->filled('month') ? (int) $request->input('month') : null;
            $year = $request->filled('year') ? (int) $request->input('year') : null;

            $data = $this->dashboardService->getAdminDashboard($month, $year);

            return ApiResponseClass::sendResponse($data, 'Dashboard de administración de soporte', 200);
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al obtener dashboard de administración', 500, [$e->getMessage()]);
        }
    }

    /**
     * Dashboard para Técnico de Campo (Métricas individuales, pendientes, próximas atenciones y accesos rápidos).
     */
    public function technician(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return ApiResponseClass::errorResponse('No autenticado', 401);
            }

            $date = $request->input('date'); // YYYY-MM-DD opcional

            $data = $this->dashboardService->getTechnicianDashboard($user, $date);

            return ApiResponseClass::sendResponse($data, 'Dashboard operativo del técnico', 200);
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al obtener dashboard del técnico', 500, [$e->getMessage()]);
        }
    }
}
