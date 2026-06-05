<?php

use App\Models\Client;
use App\Models\SupportTicket;
use App\Services\SoldUnitService;
use App\Services\SupportTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Helpers compartidos (supportSeed, clientToken, staffToken) viven en tests/Pest.php

/**
 * Crea un ticket vía HTTP como cliente y devuelve [seed, ticketId, unit].
 */
function createTicketViaApi(array $seed, array $overrides = []): int
{
    $payload = array_merge([
        'category' => 'falla',
        'priority' => 'alta',
        'subject' => 'La máquina no enciende',
        'description' => 'No muestra señal de encendido.',
    ], $overrides);

    $res = test()->withHeader('Authorization', 'Bearer ' . clientToken($seed['client']))
        ->postJson('/api/client/support/tickets', $payload);

    $res->assertCreated();

    return $res->json('data.id');
}

// ───────────────────────── Creación ─────────────────────────

it('crea un ticket con código, SLA y cobertura de garantía', function () {
    $seed = supportSeed(warrantyMonths: 24);
    app(SoldUnitService::class)->generateFromOrder($seed['order']);
    $unit = $seed['client']->soldUnits()->first();

    $res = $this->withHeader('Authorization', 'Bearer ' . clientToken($seed['client']))
        ->postJson('/api/client/support/tickets', [
            'sold_unit_id' => $unit->id,
            'category' => 'garantia',
            'subject' => 'Falla bajo garantía',
            'description' => 'Detalle del problema.',
        ]);

    $res->assertCreated()
        ->assertJsonPath('data.status', 'abierto')
        ->assertJsonPath('data.is_warranty_covered', true);

    expect($res->json('data.code'))->toStartWith('SOP-');
    expect($res->json('data.sla_due_at'))->not->toBeNull();

    $this->assertDatabaseHas('ticket_status_history', [
        'ticket_id' => $res->json('data.id'),
        'to_status' => 'abierto',
    ]);
});

it('crea un ticket sin unidad con cobertura nula', function () {
    $seed = supportSeed();

    $res = $this->withHeader('Authorization', 'Bearer ' . clientToken($seed['client']))
        ->postJson('/api/client/support/tickets', [
            'category' => 'consulta',
            'subject' => 'Consulta general',
            'description' => '¿Cómo calibro el equipo?',
        ]);

    $res->assertCreated()->assertJsonPath('data.is_warranty_covered', null);
});

it('rechaza asociar una unidad de otro cliente', function () {
    $seed = supportSeed();
    app(SoldUnitService::class)->generateFromOrder($seed['order']);
    $unit = $seed['client']->soldUnits()->first();

    $other = Client::create([
        'name' => 'Otro', 'email' => 'otro' . uniqid() . '@example.com', 'password' => bcrypt('x12345678'),
        'client_type' => 'individual', 'identity_document' => (string) random_int(10000000, 99999999),
        'document_type' => 'DNI', 'token_version' => '0', 'email_verified_at' => now(),
    ]);

    $res = $this->withHeader('Authorization', 'Bearer ' . clientToken($other))
        ->postJson('/api/client/support/tickets', [
            'sold_unit_id' => $unit->id,
            'category' => 'falla',
            'subject' => 'x',
            'description' => 'y',
        ]);

    $res->assertStatus(422);
});

// ───────────────────────── Scoping y visibilidad ─────────────────────────

it('impide ver el ticket de otro cliente', function () {
    $seed = supportSeed();
    // Se crea vía servicio (sin request autenticada previa) para no dejar el guard
    // `client` cacheado con el cliente del seed dentro del mismo test.
    $ticket = app(SupportTicketService::class)->createForClient($seed['client'], [
        'category' => 'falla', 'subject' => 'x', 'description' => 'y',
    ]);
    $ticketId = $ticket->id;

    $other = Client::create([
        'name' => 'Otro', 'email' => 'otro' . uniqid() . '@example.com', 'password' => bcrypt('x12345678'),
        'client_type' => 'individual', 'identity_document' => (string) random_int(10000000, 99999999),
        'document_type' => 'DNI', 'token_version' => '0', 'email_verified_at' => now(),
    ]);

    $this->withHeader('Authorization', 'Bearer ' . clientToken($other))
        ->getJson("/api/client/support/tickets/{$ticketId}")
        ->assertNotFound();
});

it('oculta las notas internas al cliente pero las muestra al staff', function () {
    $seed = supportSeed();
    $ticketId = createTicketViaApi($seed);
    $ticket = SupportTicket::find($ticketId);

    // Staff agrega una nota interna y un mensaje público.
    app(SupportTicketService::class)->addMessage($ticket, $seed['user'], 'Nota interna privada', true);
    app(SupportTicketService::class)->addMessage($ticket, $seed['user'], 'Respuesta pública', false);

    $clientView = $this->withHeader('Authorization', 'Bearer ' . clientToken($seed['client']))
        ->getJson("/api/client/support/tickets/{$ticketId}");
    $clientView->assertOk()->assertJsonCount(1, 'data.messages');
    expect(collect($clientView->json('data.messages'))->pluck('body'))->not->toContain('Nota interna privada');

    $staffView = $this->withHeader('Authorization', 'Bearer ' . staffToken($seed['user']))
        ->getJson("/api/support/tickets/{$ticketId}");
    $staffView->assertOk()->assertJsonCount(2, 'data.messages');
});

// ───────────────────────── Máquina de estados ─────────────────────────

it('valida las transiciones de estado', function () {
    expect(SupportTicketService::isValidTransition('abierto', 'asignado'))->toBeTrue();
    expect(SupportTicketService::isValidTransition('abierto', 'cerrado'))->toBeFalse();
    expect(SupportTicketService::isValidTransition('resuelto', 'en_proceso'))->toBeTrue();
    expect(SupportTicketService::isValidTransition('cancelado', 'en_proceso'))->toBeFalse();
});

it('rechaza una transición de estado inválida con 409', function () {
    $seed = supportSeed();
    $ticketId = createTicketViaApi($seed);

    $this->withHeader('Authorization', 'Bearer ' . staffToken($seed['user']))
        ->patchJson("/api/support/tickets/{$ticketId}/status", ['status' => 'cerrado'])
        ->assertStatus(409);
});

it('recorre el ciclo completo: asignar → resolver → calificar → reabrir', function () {
    $seed = supportSeed();
    $ticketId = createTicketViaApi($seed);
    $staffHeader = ['Authorization' => 'Bearer ' . staffToken($seed['user'])];
    $clientHeader = ['Authorization' => 'Bearer ' . clientToken($seed['client'])];

    // Asignar (abierto → asignado)
    $this->withHeaders($staffHeader)
        ->patchJson("/api/support/tickets/{$ticketId}/assign", ['assigned_user_id' => $seed['user']->id])
        ->assertOk()
        ->assertJsonPath('data.status', 'asignado')
        ->assertJsonPath('data.assigned_user_id', $seed['user']->id);

    // En proceso (asignado → en_proceso)
    $this->withHeaders($staffHeader)
        ->patchJson("/api/support/tickets/{$ticketId}/status", ['status' => 'en_proceso'])
        ->assertOk()->assertJsonPath('data.status', 'en_proceso');

    // Diagnóstico que resuelve (en_proceso → resuelto)
    $this->withHeaders($staffHeader)
        ->postJson("/api/support/tickets/{$ticketId}/diagnosis", [
            'diagnosis' => 'Fuente dañada, reemplazada',
            'parts_used' => 'Fuente 24V',
            'resolve' => true,
        ])
        ->assertOk()->assertJsonPath('data.status', 'resuelto');

    expect(SupportTicket::find($ticketId)->resolved_at)->not->toBeNull();

    // Cliente califica
    $this->withHeaders($clientHeader)
        ->postJson("/api/client/support/tickets/{$ticketId}/rate", ['rating' => 5, 'comment' => 'Excelente'])
        ->assertOk()->assertJsonPath('data.rating', 5);

    // Cliente reabre (resuelto → en_proceso)
    $this->withHeaders($clientHeader)
        ->putJson("/api/client/support/tickets/{$ticketId}/reopen", ['reason' => 'Volvió a fallar'])
        ->assertOk()->assertJsonPath('data.status', 'en_proceso');

    expect(SupportTicket::find($ticketId)->resolved_at)->toBeNull();
});

it('marca first_response_at en la primera respuesta pública del staff', function () {
    $seed = supportSeed();
    $ticketId = createTicketViaApi($seed);
    $ticket = SupportTicket::find($ticketId);

    expect($ticket->first_response_at)->toBeNull();

    app(SupportTicketService::class)->addMessage($ticket, $seed['user'], 'Hola, te ayudo', false);

    expect($ticket->fresh()->first_response_at)->not->toBeNull();
});

it('no permite calificar un ticket abierto', function () {
    $seed = supportSeed();
    $ticketId = createTicketViaApi($seed);

    $this->withHeader('Authorization', 'Bearer ' . clientToken($seed['client']))
        ->postJson("/api/client/support/tickets/{$ticketId}/rate", ['rating' => 4])
        ->assertStatus(409);
});

it('permite al staff filtrar sus tickets asignados', function () {
    $seed = supportSeed();
    $ticketId = createTicketViaApi($seed);

    app(SupportTicketService::class)->assign(SupportTicket::find($ticketId), $seed['user'], $seed['user']);

    $this->withHeader('Authorization', 'Bearer ' . staffToken($seed['user']))
        ->getJson('/api/support/tickets/mine')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});
