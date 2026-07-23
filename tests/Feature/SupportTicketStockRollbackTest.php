<?php

use App\Models\StockMovement;
use App\Models\SupportTicket;
use App\Services\SupportTicketService;
use App\Services\TicketPartService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Helpers compartidos (supportSeed, clientToken, staffToken) viven en tests/Pest.php

/**
 * Crea un ticket abierto y le agrega un repuesto que descuenta inventario.
 * Devuelve [seed, ticket, stock, part].
 */
function makeTicketWithConsumedPart(int $partQty = 4): array
{
    $seed = supportSeed();
    $stock = $seed['product']->stock;

    // Las acciones de stock registran auditoría con el usuario autenticado.
    test()->actingAs($seed['user']);

    $ticket = app(SupportTicketService::class)->createForClient($seed['client'], [
        'category' => 'falla',
        'subject' => 'La máquina no enciende',
        'description' => 'Sin señal de encendido.',
    ]);

    $part = app(TicketPartService::class)->addPart($ticket, $stock->id, $partQty, null, $seed['user']);

    return [$seed, $ticket->fresh(), $stock->fresh(), $part];
}

it('devuelve al inventario los repuestos consumidos al cancelar el ticket', function () {
    [$seed, $ticket, $stock, $part] = makeTicketWithConsumedPart(partQty: 4);

    // El repuesto ya descontó stock: 100 - 4 = 96.
    expect($stock->fresh()->quantity)->toBe(96);

    app(SupportTicketService::class)->changeStatus($ticket, 'cancelado', $seed['user'], 'Cliente desiste');

    // Stock restaurado por completo.
    expect($stock->fresh()->quantity)->toBe(100);
    expect($ticket->fresh()->status)->toBe('cancelado');

    // El movimiento de salida del repuesto queda marcado como cancelado.
    $salidaActiva = StockMovement::where('voucher_number', 'LIKE', "SOPORTE-{$ticket->id}-%")
        ->where('movement_type', 'salida')
        ->whereNull('canceled_at')
        ->exists();

    expect($salidaActiva)->toBeFalse();

    // El TicketPart se conserva como registro histórico.
    $this->assertDatabaseHas('ticket_parts', ['id' => $part->id]);
});

it('restaura el stock de varios repuestos al cancelar', function () {
    [$seed, $ticket, $stock] = makeTicketWithConsumedPart(partQty: 4);

    // Segundo repuesto sobre el mismo stock: 96 - 6 = 90.
    app(TicketPartService::class)->addPart($ticket, $stock->id, 6, null, $seed['user']);
    expect($stock->fresh()->quantity)->toBe(90);

    app(SupportTicketService::class)->changeStatus($ticket, 'cancelado', $seed['user']);

    // Ambos descuentos (4 + 6) se devuelven: 90 + 10 = 100.
    expect($stock->fresh()->quantity)->toBe(100);

    $salidasActivas = StockMovement::where('voucher_number', 'LIKE', "SOPORTE-{$ticket->id}-%")
        ->where('movement_type', 'salida')
        ->whereNull('canceled_at')
        ->count();

    expect($salidasActivas)->toBe(0);
});

it('no vuelve a devolver stock de un repuesto ya retirado manualmente', function () {
    [$seed, $ticket, $stock, $part] = makeTicketWithConsumedPart(partQty: 4);
    expect($stock->fresh()->quantity)->toBe(96);

    // El repuesto se retira antes de cancelar: su salida ya se revierte aquí.
    app(TicketPartService::class)->removePart($part, $seed['user']);
    expect($stock->fresh()->quantity)->toBe(100);

    // Al cancelar no debe volver a incrementar el stock ni fallar.
    app(SupportTicketService::class)->changeStatus($ticket, 'cancelado', $seed['user']);

    expect($stock->fresh()->quantity)->toBe(100);
});

it('no falla al cancelar un ticket sin repuestos', function () {
    $seed = supportSeed();
    $stock = $seed['product']->stock;
    test()->actingAs($seed['user']);

    $ticket = app(SupportTicketService::class)->createForClient($seed['client'], [
        'category' => 'consulta',
        'subject' => 'Consulta',
        'description' => 'Sin repuestos.',
    ]);

    app(SupportTicketService::class)->changeStatus($ticket, 'cancelado', $seed['user']);

    expect($ticket->fresh()->status)->toBe('cancelado');
    expect($stock->fresh()->quantity)->toBe(100);
});
