<?php

namespace App\Services;

use App\Models\SupportTicket;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SupportDashboardService
{
    private const OPEN_STATUSES = ['abierto', 'asignado', 'en_proceso', 'en_espera_cliente', 'en_espera_aprobacion'];

    /**
     * Dashboard para Administrador (móvil y web).
     * Soporta mes y año (default: mes actual).
     */
    public function getAdminDashboard(?int $month = null, ?int $year = null): array
    {
        $now = now();
        $targetYear = $year ?: $now->year;
        $targetMonth = $month ?: $now->month;

        $currentStart = Carbon::createFromDate($targetYear, $targetMonth, 1)->startOfMonth();
        $currentEnd = (clone $currentStart)->endOfMonth();

        $previousStart = (clone $currentStart)->subMonth()->startOfMonth();
        $previousEnd = (clone $previousStart)->endOfMonth();

        $currentMetrics = $this->calculateAdminPeriodMetrics($currentStart, $currentEnd);
        $previousMetrics = $this->calculateAdminPeriodMetrics($previousStart, $previousEnd);

        $kpis = [
            'total_tickets' => $this->formatKpi(
                $currentMetrics['total_tickets'],
                $previousMetrics['total_tickets'],
                'vs. mes anterior'
            ),
            'open_tickets' => $this->formatKpi(
                $currentMetrics['open_tickets'],
                $previousMetrics['open_tickets'],
                'vs. mes anterior'
            ),
            'unassigned_tickets' => $this->formatKpi(
                $currentMetrics['unassigned_tickets'],
                $previousMetrics['unassigned_tickets'],
                'vs. mes anterior',
                invertTrend: true
            ),
            'in_process_tickets' => $this->formatKpi(
                $currentMetrics['in_process_tickets'],
                $previousMetrics['in_process_tickets'],
                'vs. mes anterior'
            ),
            'resolved_this_month' => $this->formatKpi(
                $currentMetrics['resolved_tickets'],
                $previousMetrics['resolved_tickets'],
                'vs. mes anterior'
            ),
            'sla_breached' => $this->formatKpi(
                $currentMetrics['sla_breached'],
                $previousMetrics['sla_breached'],
                'vs. mes anterior',
                invertTrend: true
            ),
            'avg_first_response_hours' => $this->formatKpi(
                $currentMetrics['avg_first_response'],
                $previousMetrics['avg_first_response'],
                'vs. mes anterior',
                unit: 'h',
                invertTrend: true
            ),
            'avg_resolution_hours' => $this->formatKpi(
                $currentMetrics['avg_resolution'],
                $previousMetrics['avg_resolution'],
                'vs. mes anterior',
                unit: 'h',
                invertTrend: true
            ),
        ];

        return [
            'period' => [
                'month' => $targetMonth,
                'year' => $targetYear,
                'month_name' => $currentStart->translatedFormat('F'),
                'label' => $currentStart->translatedFormat('M Y'),
            ],
            'kpis' => $kpis,
            'tickets_by_status' => $this->getTicketsByStatusDistribution($currentStart, $currentEnd),
            'technicians_performance' => $this->getTechniciansPerformance($currentStart, $currentEnd),
            'recent_tickets' => $this->getRecentTickets(5),
            'alerts' => $this->getAdminAlerts(),
        ];
    }

    /**
     * Dashboard para Técnico de Campo (Carlos R., etc.).
     * Scoped al usuario técnico autenticado.
     */
    public function getTechnicianDashboard(User $technician, ?string $date = null): array
    {
        $targetDate = $date ? Carbon::parse($date) : now();
        $currentDayStart = $targetDate->copy()->startOfDay();
        $currentDayEnd = $targetDate->copy()->endOfDay();

        $prevDayStart = $targetDate->copy()->subDay()->startOfDay();
        $prevDayEnd = $targetDate->copy()->subDay()->endOfDay();

        // Consultas base del técnico autenticado
        $myActiveQ = fn () => SupportTicket::where('assigned_user_id', $technician->id)
            ->whereIn('status', self::OPEN_STATUSES);

        // 1. Métricas con comparativa vs ayer
        $currentMyTickets = $myActiveQ()->count();
        $prevMyTickets = SupportTicket::where('assigned_user_id', $technician->id)
            ->where('created_at', '<=', $prevDayEnd)
            ->where(function ($q) use ($prevDayEnd) {
                $q->whereNull('resolved_at')->orWhere('resolved_at', '>', $prevDayEnd);
            })->count();

        $currentPending = $myActiveQ()->whereIn('status', ['abierto', 'asignado', 'en_espera_aprobacion'])->count();
        $prevPending = SupportTicket::where('assigned_user_id', $technician->id)
            ->whereIn('status', ['abierto', 'asignado', 'en_espera_aprobacion'])
            ->where('created_at', '<=', $prevDayEnd)
            ->count();

        $currentInProcess = $myActiveQ()->where('status', 'en_proceso')->count();
        $prevInProcess = SupportTicket::where('assigned_user_id', $technician->id)
            ->where('status', 'en_proceso')
            ->where('created_at', '<=', $prevDayEnd)
            ->count();

        $resolvedToday = SupportTicket::where('assigned_user_id', $technician->id)
            ->whereNotNull('resolved_at')
            ->whereBetween('resolved_at', [$currentDayStart, $currentDayEnd])
            ->count();

        $resolvedYesterday = SupportTicket::where('assigned_user_id', $technician->id)
            ->whereNotNull('resolved_at')
            ->whereBetween('resolved_at', [$prevDayStart, $prevDayEnd])
            ->count();

        // Total histórico de resueltos del técnico, y cuántos había al cierre de ayer.
        $resolvedTotal = SupportTicket::where('assigned_user_id', $technician->id)
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '<=', $currentDayEnd)
            ->count();

        $resolvedTotalPrev = SupportTicket::where('assigned_user_id', $technician->id)
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '<=', $prevDayEnd)
            ->count();

        $currentHighPriority = $myActiveQ()->whereIn('priority', ['alta', 'urgente'])->count();
        $prevHighPriority = SupportTicket::where('assigned_user_id', $technician->id)
            ->whereIn('priority', ['alta', 'urgente'])
            ->where('created_at', '<=', $prevDayEnd)
            ->where(function ($q) use ($prevDayEnd) {
                $q->whereNull('resolved_at')->orWhere('resolved_at', '>', $prevDayEnd);
            })->count();

        $currentDueSoon = $myActiveQ()->whereNotNull('sla_due_at')
            ->where('sla_due_at', '<=', now()->addHours(SupportTicket::SLA_DUE_SOON_HOURS))
            ->count();

        $prevDueSoon = SupportTicket::where('assigned_user_id', $technician->id)
            ->whereNotNull('sla_due_at')
            ->where('sla_due_at', '<=', $prevDayEnd->copy()->addHours(SupportTicket::SLA_DUE_SOON_HOURS))
            ->where('created_at', '<=', $prevDayEnd)
            ->count();

        $kpis = [
            'my_tickets' => $this->formatKpi($currentMyTickets, $prevMyTickets, 'vs. ayer'),
            'pending' => $this->formatKpi($currentPending, $prevPending, 'vs. ayer'),
            'in_process' => $this->formatKpi($currentInProcess, $prevInProcess, 'vs. ayer'),
            'resolved_total' => $this->formatKpi($resolvedTotal, $resolvedTotalPrev, 'vs. ayer'),
            'resolved_today' => $this->formatKpi($resolvedToday, $resolvedYesterday, 'vs. ayer'),
            'high_priority' => $this->formatKpi($currentHighPriority, $prevHighPriority, 'vs. ayer', invertTrend: true),
            'due_soon' => $this->formatKpi($currentDueSoon, $prevDueSoon, 'vs. ayer', invertTrend: true),
        ];

        // 2. Lista "Mis tickets pendientes"
        $pendingTickets = SupportTicket::with(['client', 'soldUnit.product', 'product'])
            ->where('assigned_user_id', $technician->id)
            ->whereIn('status', self::OPEN_STATUSES)
            ->orderByRaw("CASE priority WHEN 'urgente' THEN 1 WHEN 'alta' THEN 2 WHEN 'media' THEN 3 WHEN 'baja' THEN 4 ELSE 5 END")
            ->orderBy('scheduled_at')
            ->limit(10)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'code' => $t->code,
                'priority' => $t->priority ?? 'media',
                'status' => $t->status ?? 'abierto',
                'client_name' => $t->client?->name ?? 'Cliente N/D',
                'equipment' => $t->soldUnit?->product?->name ?? $t->product?->name ?? 'Multifuncional / Impresora',
                'problem' => $t->subject ?: ($t->description ? Str::limit($t->description, 60) : 'Sin detalle'),
                'scheduled_at' => $t->scheduled_at?->toIso8601String(),
                'scheduled_at_formatted' => $t->scheduled_at ? $t->scheduled_at->translatedFormat('d M. Y • H:i \h') : null,
                'created_at_formatted' => $t->created_at->translatedFormat('d M. Y • H:i \h'),
            ])->all();

        // 3. Próximas atenciones
        $upcomingVisits = SupportTicket::with(['client', 'serviceAddress'])
            ->where('assigned_user_id', $technician->id)
            ->whereNotNull('scheduled_at')
            ->whereBetween('scheduled_at', [$currentDayStart, $currentDayEnd])
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn ($t) => [
                'ticket_id' => $t->id,
                'code' => $t->code,
                'client_name' => $t->client?->name ?? 'Cliente N/D',
                'address_full' => $t->serviceAddress?->address_full ?? 'Dirección del cliente',
                'district' => $t->serviceAddress?->district,
                'reference' => $t->serviceAddress?->reference,
                'latitude' => $t->serviceAddress?->latitude ? (float) $t->serviceAddress->latitude : null,
                'longitude' => $t->serviceAddress?->longitude ? (float) $t->serviceAddress->longitude : null,
                'scheduled_at' => $t->scheduled_at->toIso8601String(),
                'time_formatted' => 'Hoy ' . $t->scheduled_at->format('H:i \h'),
                'status' => $t->status,
            ])->all();

        // 4. Accesos rápidos
        $quickActions = [
            ['key' => 'my_tickets', 'label' => 'Mis tickets', 'icon' => 'confirmation_number_outlined', 'route' => '/staff/tickets'],
            ['key' => 'search', 'label' => 'Buscar ticket', 'icon' => 'search', 'route' => '/staff/tickets'],
            ['key' => 'qr_scanner', 'label' => 'Escanear QR', 'icon' => 'qr_code_scanner', 'route' => '/staff/scanner'],
            ['key' => 'client_location', 'label' => 'Ubicación cliente', 'icon' => 'location_on_outlined', 'action' => 'map'],
            ['key' => 'register_diagnosis', 'label' => 'Registrar diagnóstico', 'icon' => 'assignment_outlined', 'route' => '/staff/tickets'],
            ['key' => 'mark_resolved', 'label' => 'Marcar resuelto', 'icon' => 'check_circle_outline', 'route' => '/staff/tickets'],
        ];

        return [
            'technician' => [
                'id' => $technician->id,
                'name' => $technician->name,
                'email' => $technician->email,
                'is_available' => (bool) $technician->is_available,
            ],
            'date' => [
                'selected' => $targetDate->toDateString(),
                'label' => $targetDate->translatedFormat('d M. Y'),
            ],
            'kpis' => $kpis,
            'pending_tickets' => $pendingTickets,
            'upcoming_visits' => $upcomingVisits,
            'quick_actions' => $quickActions,
        ];
    }

    private function calculateAdminPeriodMetrics(Carbon $start, Carbon $end): array
    {
        $scoped = fn () => SupportTicket::whereBetween('created_at', [$start, $end]);

        $avgFirstResponse = DB::table('support_tickets')
            ->whereNotNull('first_response_at')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (first_response_at - created_at)) / 3600) as v')
            ->value('v') ?? 0;

        $avgResolution = DB::table('support_tickets')
            ->whereNotNull('resolved_at')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (resolved_at - created_at)) / 3600) as v')
            ->value('v') ?? 0;

        $slaBreached = (clone $scoped())->whereNotNull('sla_due_at')
            ->where(function ($q) {
                $q->where(function ($x) {
                    $x->whereNull('first_response_at')->where('sla_due_at', '<', now());
                })->orWhereColumn('first_response_at', '>', 'sla_due_at');
            })->count();

        return [
            'total_tickets' => (clone $scoped())->count(),
            'open_tickets' => (clone $scoped())->whereIn('status', self::OPEN_STATUSES)->count(),
            'unassigned_tickets' => (clone $scoped())->whereNull('assigned_user_id')->whereIn('status', self::OPEN_STATUSES)->count(),
            'in_process_tickets' => (clone $scoped())->where('status', 'en_proceso')->count(),
            'resolved_tickets' => SupportTicket::whereNotNull('resolved_at')->whereBetween('resolved_at', [$start, $end])->count(),
            'sla_breached' => $slaBreached,
            'avg_first_response' => round((float) $avgFirstResponse, 1),
            'avg_resolution' => round((float) $avgResolution, 1),
        ];
    }

    private function formatKpi($current, $previous, string $label, string $unit = '', bool $invertTrend = false): array
    {
        $cur = round((float) $current, 1);
        $prev = round((float) $previous, 1);
        $diff = round($cur - $prev, 1);

        if ($prev == 0.0) {
            $pct = $cur > 0 ? 100.0 : 0.0;
        } else {
            $pct = round(($diff / $prev) * 100, 1);
        }

        $direction = 'neutral';
        if ($diff > 0) {
            $direction = 'up';
        } elseif ($diff < 0) {
            $direction = 'down';
        }

        $isFavorable = $invertTrend ? ($direction === 'down') : ($direction === 'up');

        return [
            'value' => $cur,
            'formatted_value' => $unit ? "{$cur} {$unit}" : (string) (is_int($current) || $cur == (int) $cur ? (int) $cur : $cur),
            'previous_value' => $prev,
            'diff' => $diff,
            'change_pct' => abs($pct),
            'direction' => $direction,
            'is_favorable' => $isFavorable,
            'period_label' => $label,
        ];
    }

    private function getTicketsByStatusDistribution(Carbon $start, Carbon $end): array
    {
        $statusCounts = SupportTicket::whereBetween('created_at', [$start, $end])
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $total = array_sum($statusCounts);

        $pendingCount = ($statusCounts['abierto'] ?? 0) + ($statusCounts['en_espera_aprobacion'] ?? 0);
        $assignedCount = $statusCounts['asignado'] ?? 0;
        $inProcessCount = ($statusCounts['en_proceso'] ?? 0) + ($statusCounts['en_espera_cliente'] ?? 0);
        $resolvedCount = $statusCounts['resuelto'] ?? 0;
        $closedCount = ($statusCounts['cerrado'] ?? 0) + ($statusCounts['cancelado'] ?? 0);

        return [
            'total' => $total,
            'categories' => [
                'pendientes' => [
                    'label' => 'Pendientes',
                    'count' => $pendingCount,
                    'percentage' => $total > 0 ? round(($pendingCount / $total) * 100, 1) : 0,
                    'color' => '#0090D8',
                ],
                'asignados' => [
                    'label' => 'Asignados',
                    'count' => $assignedCount,
                    'percentage' => $total > 0 ? round(($assignedCount / $total) * 100, 1) : 0,
                    'color' => '#F0A830',
                ],
                'en_proceso' => [
                    'label' => 'En proceso',
                    'count' => $inProcessCount,
                    'percentage' => $total > 0 ? round(($inProcessCount / $total) * 100, 1) : 0,
                    'color' => '#8E44AD',
                ],
                'resueltos' => [
                    'label' => 'Resueltos',
                    'count' => $resolvedCount,
                    'percentage' => $total > 0 ? round(($resolvedCount / $total) * 100, 1) : 0,
                    'color' => '#2ECC71',
                ],
                'cerrados' => [
                    'label' => 'Cerrados',
                    'count' => $closedCount,
                    'percentage' => $total > 0 ? round(($closedCount / $total) * 100, 1) : 0,
                    'color' => '#34495E',
                ],
            ],
            'raw_by_status' => $statusCounts,
        ];
    }

    private function getTechniciansPerformance(Carbon $start, Carbon $end): array
    {
        $techs = User::whereHas('role', fn ($q) => $q->where('name', 'Tecnico'))
            ->where('is_active', true)
            ->get();

        $assignedMap = SupportTicket::whereBetween('created_at', [$start, $end])
            ->whereNotNull('assigned_user_id')
            ->selectRaw('assigned_user_id, count(*) as c')
            ->groupBy('assigned_user_id')
            ->pluck('c', 'assigned_user_id');

        $inProcessMap = SupportTicket::whereNotNull('assigned_user_id')
            ->where('status', 'en_proceso')
            ->selectRaw('assigned_user_id, count(*) as c')
            ->groupBy('assigned_user_id')
            ->pluck('c', 'assigned_user_id');

        $resolvedMap = SupportTicket::whereNotNull('assigned_user_id')
            ->whereNotNull('resolved_at')
            ->whereBetween('resolved_at', [$start, $end])
            ->selectRaw('assigned_user_id, count(*) as c')
            ->groupBy('assigned_user_id')
            ->pluck('c', 'assigned_user_id');

        return $techs->map(fn ($t) => [
            'id' => $t->id,
            'name' => $t->name,
            'avatar_url' => $t->avatar_url ?? null,
            'assigned' => (int) ($assignedMap[$t->id] ?? 0),
            'in_process' => (int) ($inProcessMap[$t->id] ?? 0),
            'resolved' => (int) ($resolvedMap[$t->id] ?? 0),
        ])->values()->all();
    }

    private function getRecentTickets(int $limit = 5): array
    {
        return SupportTicket::with(['client', 'soldUnit.product', 'product', 'assignedUser'])
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'code' => $t->code,
                'client' => $t->client?->name ?? 'Cliente N/D',
                'equipment' => $t->soldUnit?->product?->name ?? $t->product?->name ?? 'Multifuncional / Impresora',
                'problem' => $t->subject ?: ($t->description ? Str::limit($t->description, 50) : 'Sin detalle'),
                'technician' => $t->assignedUser?->name ?? 'Sin asignar',
                'priority' => $t->priority ?? 'media',
                'status' => $t->status ?? 'abierto',
                'created_at' => $t->created_at->translatedFormat('d M. Y • H:i \h'),
            ])->all();
    }

    private function getAdminAlerts(): array
    {
        $openQ = fn () => SupportTicket::whereIn('status', self::OPEN_STATUSES);

        $unassigned = $openQ()->whereNull('assigned_user_id')->count();
        $dueSoon = $openQ()->whereNotNull('sla_due_at')
            ->whereBetween('sla_due_at', [now(), now()->addHours(SupportTicket::SLA_DUE_SOON_HOURS)])
            ->count();
        $breached = $openQ()->whereNotNull('sla_due_at')
            ->where('sla_due_at', '<', now())
            ->count();

        return [
            'unassigned_tickets' => $unassigned,
            'sla_due_soon' => $dueSoon,
            'sla_breached' => $breached,
            'has_critical' => ($unassigned > 0 || $dueSoon > 0 || $breached > 0),
        ];
    }
}
