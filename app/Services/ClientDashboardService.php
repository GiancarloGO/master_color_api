<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Order;
use App\Models\SoldUnit;
use App\Models\SupportTicket;

class ClientDashboardService
{
    private const OPEN_STATUSES = ['abierto', 'asignado', 'en_proceso', 'en_espera_cliente', 'en_espera_aprobacion'];

    /**
     * Resumen consolidado para el home del cliente (App móvil).
     */
    public function getSummary(Client $client): array
    {
        $unitsCount = SoldUnit::where('client_id', $client->id)->count();
        $ordersCount = Order::where('client_id', $client->id)->count();
        $openTicketsCount = SupportTicket::where('client_id', $client->id)
            ->whereIn('status', self::OPEN_STATUSES)
            ->count();

        // Mantenimientos programados: tickets con visita agendada a futuro
        $maintenancesCount = SupportTicket::where('client_id', $client->id)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '>=', now()->startOfDay())
            ->count();

        $banners = [
            [
                'id' => 1,
                'title' => "Soluciones completas\npara tus equipos",
                'subtitle' => "Venta, reparación y mantenimiento de impresoras, fotocopiadoras y multifuncionales.",
                'tag' => 'Master Color',
                'action_route' => '/client/units',
            ],
        ];

        $recentTickets = SupportTicket::with(['product', 'soldUnit.product'])
            ->where('client_id', $client->id)
            ->latest('created_at')
            ->limit(3)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'code' => $t->code,
                'subject' => $t->subject ?: 'Ticket #' . $t->code,
                'status' => $t->status,
                'priority' => $t->priority,
                'equipment' => $t->soldUnit?->product?->name ?? $t->product?->name ?? 'Equipo',
                'created_at_formatted' => $t->created_at->format('d/m/Y'),
            ])->all();

        $recentOrders = Order::where('client_id', $client->id)
            ->latest('created_at')
            ->limit(2)
            ->get()
            ->map(fn ($o) => [
                'id' => $o->id,
                'order_code' => 'PED-' . str_pad((string) $o->id, 5, '0', STR_PAD_LEFT),
                'status' => $o->status,
                'total' => (float) $o->total,
                'created_at_formatted' => $o->created_at->format('d/m/Y'),
            ])->all();

        $quickActions = [
            ['key' => 'new_ticket', 'label' => 'Nuevo Ticket', 'icon' => 'add_circle_outline', 'route' => '/client/tickets/new'],
            ['key' => 'order_status', 'label' => "Estado de\nPedidos", 'icon' => 'local_shipping_outlined', 'route' => '/client/orders'],
            ['key' => 'contact', 'label' => 'Contacto', 'icon' => 'support_agent_outlined', 'action' => 'contact'],
            ['key' => 'location', 'label' => 'Ubicación', 'icon' => 'location_on_outlined', 'action' => 'location'],
        ];

        return [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'email' => $client->email,
            ],
            'kpis' => [
                'units' => $unitsCount,
                'orders' => $ordersCount,
                'open_tickets' => $openTicketsCount,
                'maintenances' => $maintenancesCount,
            ],
            'banners' => $banners,
            'recent_tickets' => $recentTickets,
            'recent_orders' => $recentOrders,
            'quick_actions' => $quickActions,
        ];
    }
}
