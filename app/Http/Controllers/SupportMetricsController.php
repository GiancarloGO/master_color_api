<?php

namespace App\Http\Controllers;

use App\Classes\ApiResponseClass;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Http\Request;

class SupportMetricsController extends Controller
{
    private const OPEN_STATUSES = ['abierto', 'asignado', 'en_proceso', 'en_espera_cliente'];

    /**
     * Indicadores del área de soporte (SLA, carga por técnico).
     */
    public function index(Request $request)
    {
        try {
            $dateFrom = $request->input('date_from');
            $dateTo = $request->input('date_to');

            // Cierre que aplica el rango de fechas (sobre created_at) a una consulta base.
            $scoped = function () use ($dateFrom, $dateTo) {
                $q = SupportTicket::query();
                if ($dateFrom) {
                    $q->whereDate('created_at', '>=', $dateFrom);
                }
                if ($dateTo) {
                    $q->whereDate('created_at', '<=', $dateTo);
                }
                return $q;
            };

            $avgFirstResponse = (clone $scoped())->whereNotNull('first_response_at')
                ->selectRaw('AVG(EXTRACT(EPOCH FROM (first_response_at - created_at)) / 3600) as v')
                ->value('v');

            $avgResolution = (clone $scoped())->whereNotNull('resolved_at')
                ->selectRaw('AVG(EXTRACT(EPOCH FROM (resolved_at - created_at)) / 3600) as v')
                ->value('v');

            $slaBreached = (clone $scoped())->whereNotNull('sla_due_at')
                ->where(function ($q) {
                    $q->where(function ($x) {
                        $x->whereNull('first_response_at')->where('sla_due_at', '<', now());
                    })->orWhereColumn('first_response_at', '>', 'sla_due_at');
                })->count();

            $byStatus = (clone $scoped())
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

            $techRows = (clone $scoped())->whereNotNull('assigned_user_id')
                ->selectRaw('assigned_user_id, COUNT(*) as assigned, COUNT(resolved_at) as resolved')
                ->groupBy('assigned_user_id')
                ->get();

            $names = User::whereIn('id', $techRows->pluck('assigned_user_id'))->pluck('name', 'id');

            $byTechnician = $techRows->map(fn ($row) => [
                'user_id' => (int) $row->assigned_user_id,
                'name' => $names[$row->assigned_user_id] ?? 'N/D',
                'assigned' => (int) $row->assigned,
                'resolved' => (int) $row->resolved,
            ])->values();

            $metrics = [
                'total_tickets' => (clone $scoped())->count(),
                'open_tickets' => (clone $scoped())->whereIn('status', self::OPEN_STATUSES)->count(),
                'unassigned_tickets' => (clone $scoped())->whereNull('assigned_user_id')
                    ->whereIn('status', self::OPEN_STATUSES)->count(),
                'resolved_this_month' => SupportTicket::whereNotNull('resolved_at')
                    ->whereMonth('resolved_at', now()->month)
                    ->whereYear('resolved_at', now()->year)
                    ->count(),
                'avg_first_response_hours' => round((float) $avgFirstResponse, 1),
                'avg_resolution_hours' => round((float) $avgResolution, 1),
                'sla_breached' => $slaBreached,
                'by_status' => $byStatus,
                'by_technician' => $byTechnician,
            ];

            return ApiResponseClass::sendResponse($metrics, 'Métricas de soporte', 200);
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al obtener métricas', 500, [$e->getMessage()]);
        }
    }
}
