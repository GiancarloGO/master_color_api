<?php

use App\Models\SoldUnit;
use App\Models\SupportTicket;
use App\Services\SoldUnitService;
use App\Services\SupportTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Helpers compartidos (supportSeed, clientToken, staffToken) viven en tests/Pest.php

// ───────────────────────── Métricas ─────────────────────────

it('devuelve métricas de soporte con la forma esperada', function () {
    $seed = supportSeed();
    $service = app(SupportTicketService::class);

    // 3 tickets: uno asignado+resuelto, uno asignado, uno abierto sin asignar.
    $t1 = $service->createForClient($seed['client'], ['category' => 'falla', 'subject' => 'a', 'description' => 'x']);
    $service->assign($t1, $seed['user'], $seed['user']);
    $service->changeStatus($t1->fresh(), 'en_proceso', $seed['user']);
    $service->addMessage($t1->fresh(), $seed['user'], 'respondo', false); // first_response_at
    $service->registerDiagnosis($t1->fresh(), ['diagnosis' => 'ok', 'resolve' => true], $seed['user']);

    $t2 = $service->createForClient($seed['client'], ['category' => 'consulta', 'subject' => 'b', 'description' => 'y']);
    $service->assign($t2, $seed['user'], $seed['user']);

    $service->createForClient($seed['client'], ['category' => 'otro', 'subject' => 'c', 'description' => 'z']);

    $res = $this->withHeader('Authorization', 'Bearer ' . staffToken($seed['user']))
        ->getJson('/api/support/metrics');

    $res->assertOk()
        ->assertJsonPath('data.total_tickets', 3)
        ->assertJsonPath('data.unassigned_tickets', 1)
        ->assertJsonPath('data.resolved_this_month', 1)
        ->assertJsonStructure([
            'data' => [
                'total_tickets', 'open_tickets', 'unassigned_tickets', 'resolved_this_month',
                'avg_first_response_hours', 'avg_resolution_hours', 'sla_breached',
                'by_status', 'by_technician',
            ],
        ]);

    // El técnico tiene 2 asignados y 1 resuelto.
    $tech = collect($res->json('data.by_technician'))->firstWhere('user_id', $seed['user']->id);
    expect($tech['assigned'])->toBe(2);
    expect($tech['resolved'])->toBe(1);
});

it('exige autenticación de staff para las métricas', function () {
    $this->getJson('/api/support/metrics')->assertUnauthorized();
});

// ───────────────────────── Asignar nº de serie ─────────────────────────

it('permite al staff asignar número de serie y estado a una unidad', function () {
    $seed = supportSeed();
    app(SoldUnitService::class)->generateFromOrder($seed['order']);
    $unit = SoldUnit::where('order_id', $seed['order']->id)->first();

    expect($unit->serial_number)->toBeNull();

    $res = $this->withHeader('Authorization', 'Bearer ' . staffToken($seed['user']))
        ->patchJson("/api/support/units/{$unit->id}", [
            'serial_number' => 'SN-ASIGNADO-001',
            'status' => 'en_servicio',
        ]);

    $res->assertOk()
        ->assertJsonPath('data.serial_number', 'SN-ASIGNADO-001')
        ->assertJsonPath('data.status', 'en_servicio');

    $this->assertDatabaseHas('sold_units', [
        'id' => $unit->id,
        'serial_number' => 'SN-ASIGNADO-001',
        'status' => 'en_servicio',
    ]);
});

it('rechaza nº de serie duplicado para el mismo producto', function () {
    $seed = supportSeed(orderQty: 2);
    app(SoldUnitService::class)->generateFromOrder($seed['order']);
    $units = SoldUnit::where('order_id', $seed['order']->id)->get();
    $header = ['Authorization' => 'Bearer ' . staffToken($seed['user'])];

    $this->withHeaders($header)
        ->patchJson("/api/support/units/{$units[0]->id}", ['serial_number' => 'DUP-1'])
        ->assertOk();

    $this->withHeaders($header)
        ->patchJson("/api/support/units/{$units[1]->id}", ['serial_number' => 'DUP-1'])
        ->assertStatus(422);
});
