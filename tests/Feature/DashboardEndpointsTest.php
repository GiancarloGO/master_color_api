<?php

use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\SoldUnit;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('devuelve el dashboard de administración con la estructura esperada', function () {
    $adminRole = Role::firstOrCreate(['name' => 'Admin'], ['description' => 'Administrador']);
    $user = User::factory()->create([
        'role_id' => $adminRole->id,
        'dni' => '8' . random_int(1000000, 9999999),
        'token_version' => '0',
    ]);

    $res = $this->withHeader('Authorization', 'Bearer ' . staffToken($user))
        ->getJson('/api/support/dashboard/admin');

    $res->assertOk()
        ->assertJsonStructure([
            'success',
            'message',
            'status',
            'data' => [
                'period' => ['month', 'year', 'month_name', 'label'],
                'kpis' => [
                    'total_tickets' => ['value', 'formatted_value', 'previous_value', 'diff', 'change_pct', 'direction', 'is_favorable', 'period_label'],
                    'open_tickets',
                    'unassigned_tickets',
                    'in_process_tickets',
                    'resolved_this_month',
                    'sla_breached',
                    'avg_first_response_hours',
                    'avg_resolution_hours',
                ],
                'tickets_by_status' => [
                    'total',
                    'categories' => [
                        'pendientes',
                        'asignados',
                        'en_proceso',
                        'resueltos',
                        'cerrados',
                    ],
                ],
                'technicians_performance',
                'recent_tickets',
                'alerts' => ['unassigned_tickets', 'sla_due_soon', 'sla_breached', 'has_critical'],
            ],
        ]);
});

it('exige autenticación staff para el dashboard de administración', function () {
    $this->getJson('/api/support/dashboard/admin')->assertUnauthorized();
});

it('devuelve el dashboard del técnico con la estructura esperada', function () {
    $techRole = Role::firstOrCreate(['name' => 'Tecnico'], ['description' => 'Técnico de soporte']);
    $technician = User::factory()->create([
        'role_id' => $techRole->id,
        'dni' => '8' . random_int(1000000, 9999999),
        'token_version' => '0',
        'is_active' => true,
        'is_available' => true,
    ]);

    $res = $this->withHeader('Authorization', 'Bearer ' . staffToken($technician))
        ->getJson('/api/support/dashboard/technician');

    $res->assertOk()
        ->assertJsonStructure([
            'success',
            'message',
            'status',
            'data' => [
                'technician' => ['id', 'name', 'email', 'is_available'],
                'date' => ['selected', 'label'],
                'kpis' => [
                    'my_tickets' => ['value', 'formatted_value', 'previous_value', 'diff', 'change_pct', 'direction', 'is_favorable', 'period_label'],
                    'pending',
                    'in_process',
                    'resolved_total',
                    'resolved_today',
                    'high_priority',
                    'due_soon',
                ],
                'pending_tickets',
                'upcoming_visits',
                'quick_actions',
            ],
        ]);
});

it('devuelve el resumen de inicio para el cliente con la estructura esperada', function () {
    $client = Client::create([
        'name' => 'Cliente Test',
        'email' => 'client.' . uniqid() . '@example.com',
        'password' => bcrypt('secret123'),
        'client_type' => 'individual',
        'identity_document' => (string) random_int(10000000, 99999999),
        'document_type' => 'DNI',
        'token_version' => '0',
        'email_verified_at' => now(),
    ]);

    $res = $this->withHeader('Authorization', 'Bearer ' . clientToken($client))
        ->getJson('/api/client/dashboard/summary');

    $res->assertOk()
        ->assertJsonStructure([
            'success',
            'message',
            'status',
            'data' => [
                'client' => ['id', 'name', 'email'],
                'kpis' => [
                    'units',
                    'orders',
                    'open_tickets',
                    'maintenances',
                ],
                'banners',
                'recent_tickets',
                'recent_orders',
                'quick_actions',
            ],
        ])
        ->assertJsonPath('data.kpis.units', 0)
        ->assertJsonPath('data.kpis.orders', 0)
        ->assertJsonPath('data.kpis.open_tickets', 0);
});

it('exige autenticación de cliente para el resumen de inicio', function () {
    $this->getJson('/api/client/dashboard/summary')->assertUnauthorized();
});

it('calcula métricas del técnico con tickets asignados y visitas', function () {
    $techRole = Role::firstOrCreate(['name' => 'Tecnico'], ['description' => 'Técnico de soporte']);
    $technician = User::factory()->create([
        'role_id' => $techRole->id,
        'dni' => '8' . random_int(1000000, 9999999),
        'token_version' => '0',
        'is_active' => true,
        'is_available' => true,
    ]);

    $client = Client::create([
        'name' => 'Empresa Ramos',
        'email' => 'ramos.' . uniqid() . '@example.com',
        'password' => bcrypt('secret123'),
        'client_type' => 'company',
        'identity_document' => '20123456789',
        'document_type' => 'RUC',
        'token_version' => '0',
        'email_verified_at' => now(),
    ]);

    // Ticket asignado en proceso
    SupportTicket::create([
        'code' => 'TK-00125',
        'client_id' => $client->id,
        'assigned_user_id' => $technician->id,
        'category' => 'falla',
        'priority' => 'alta',
        'subject' => 'No imprime',
        'description' => 'Multifuncional Epson no imprime nada',
        'status' => 'en_proceso',
        'channel' => 'app',
        'service_type' => 'domicilio',
        'scheduled_at' => now()->setTime(9, 30),
    ]);

    $res = $this->withHeader('Authorization', 'Bearer ' . staffToken($technician))
        ->getJson('/api/support/dashboard/technician');

    $res->assertOk()
        ->assertJsonPath('data.kpis.my_tickets.value', 1)
        ->assertJsonPath('data.kpis.in_process.value', 1)
        ->assertJsonPath('data.pending_tickets.0.code', 'TK-00125')
        ->assertJsonPath('data.pending_tickets.0.client_name', 'Empresa Ramos')
        ->assertJsonPath('data.pending_tickets.0.priority', 'alta')
        ->assertJsonPath('data.upcoming_visits.0.code', 'TK-00125');
});
