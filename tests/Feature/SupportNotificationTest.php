<?php

use App\Events\TicketAssigned;
use App\Events\TicketMessageCreated;
use App\Events\TicketStatusChanged;
use App\Mail\TicketStatusNotification;
use App\Models\Client;
use App\Models\DeviceToken;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\PushNotificationService;
use App\Services\SupportTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

// Helpers compartidos (supportSeed, clientToken, staffToken) viven en tests/Pest.php

function makeTicket(array $seed): SupportTicket
{
    return app(SupportTicketService::class)->createForClient($seed['client'], [
        'category' => 'falla',
        'subject' => 'No enciende',
        'description' => 'Detalle',
    ]);
}

// ───────────────────────── Device tokens ─────────────────────────

it('el cliente registra un token de dispositivo', function () {
    $seed = supportSeed();

    $this->withHeader('Authorization', 'Bearer ' . clientToken($seed['client']))
        ->postJson('/api/client/devices', ['token' => 'fcm-abc-123', 'platform' => 'android'])
        ->assertOk();

    $this->assertDatabaseHas('device_tokens', [
        'token' => 'fcm-abc-123',
        'tokenable_type' => Client::class,
        'tokenable_id' => $seed['client']->id,
        'platform' => 'android',
    ]);
});

it('reasigna un token existente al nuevo dueño sin duplicar', function () {
    $seed = supportSeed();
    DeviceToken::create([
        'token' => 'fcm-shared', 'platform' => 'ios',
        'tokenable_type' => User::class, 'tokenable_id' => $seed['user']->id,
    ]);

    $this->withHeader('Authorization', 'Bearer ' . clientToken($seed['client']))
        ->postJson('/api/client/devices', ['token' => 'fcm-shared', 'platform' => 'android'])
        ->assertOk();

    expect(DeviceToken::where('token', 'fcm-shared')->count())->toBe(1);
    $this->assertDatabaseHas('device_tokens', [
        'token' => 'fcm-shared',
        'tokenable_type' => Client::class,
        'tokenable_id' => $seed['client']->id,
    ]);
});

it('el cliente elimina su token', function () {
    $seed = supportSeed();
    $seed['client']->deviceTokens()->create(['token' => 'fcm-del', 'platform' => 'android']);

    $this->withHeader('Authorization', 'Bearer ' . clientToken($seed['client']))
        ->deleteJson('/api/client/devices/fcm-del')
        ->assertOk();

    $this->assertDatabaseMissing('device_tokens', ['token' => 'fcm-del']);
});

it('el staff registra un token de dispositivo', function () {
    $seed = supportSeed();

    $this->withHeader('Authorization', 'Bearer ' . staffToken($seed['user']))
        ->postJson('/api/support/devices', ['token' => 'fcm-staff', 'platform' => 'ios'])
        ->assertOk();

    $this->assertDatabaseHas('device_tokens', [
        'token' => 'fcm-staff',
        'tokenable_type' => User::class,
        'tokenable_id' => $seed['user']->id,
    ]);
});

// ───────────────────────── Eventos despachados ─────────────────────────

it('despacha eventos al cambiar estado, asignar y enviar mensaje', function () {
    $seed = supportSeed();
    $ticket = makeTicket($seed);

    Event::fake([TicketStatusChanged::class, TicketAssigned::class, TicketMessageCreated::class]);

    $service = app(SupportTicketService::class);
    $service->assign($ticket, $seed['user'], $seed['user']);
    $service->changeStatus($ticket->fresh(), 'en_proceso', $seed['user']);
    $service->addMessage($ticket->fresh(), $seed['user'], 'Hola', false);

    Event::assertDispatched(TicketAssigned::class);
    Event::assertDispatched(TicketStatusChanged::class);
    Event::assertDispatched(TicketMessageCreated::class);
});

// ───────────────────────── Listeners → push/email ─────────────────────────

it('notifica al cliente (push + email) cuando el staff cambia el estado', function () {
    $seed = supportSeed();
    $ticket = makeTicket($seed);
    $seed['client']->deviceTokens()->create(['token' => 'fcm-cli', 'platform' => 'android']);

    $spy = Mockery::spy(PushNotificationService::class);
    app()->instance(PushNotificationService::class, $spy);
    Mail::fake();

    app(SupportTicketService::class)->changeStatus($ticket, 'en_proceso', $seed['user']);

    $spy->shouldHaveReceived('sendToModel')
        ->withArgs(fn ($model) => $model instanceof Client && $model->id === $seed['client']->id);

    Mail::assertSent(TicketStatusNotification::class);
});

it('notifica al técnico asignado cuando el cliente responde', function () {
    $seed = supportSeed();
    $ticket = makeTicket($seed);
    $ticket->update(['assigned_user_id' => $seed['user']->id]);

    $spy = Mockery::spy(PushNotificationService::class);
    app()->instance(PushNotificationService::class, $spy);

    app(SupportTicketService::class)->addMessage($ticket->fresh(), $seed['client'], 'Sigo con el problema', false);

    $spy->shouldHaveReceived('sendToModel')
        ->withArgs(fn ($model) => $model instanceof User && $model->id === $seed['user']->id);
});

// ───────────────────────── PushNotificationService robusto ─────────────────────────

it('el servicio de push no falla sin configuración FCM ni tokens', function () {
    config(['services.fcm.key' => null]);
    $service = app(PushNotificationService::class);

    $service->sendToTokens([], 'T', 'B');
    $service->sendToTokens(['tok1', 'tok2'], 'T', 'B');

    expect(true)->toBeTrue(); // no se lanzó excepción
});

it('renderiza el correo de cambio de estado sin errores', function () {
    $seed = supportSeed();
    $ticket = makeTicket($seed)->load('client');

    $html = (new TicketStatusNotification($ticket, 'abierto'))->render();

    expect($html)->toContain($ticket->code);
});
