<?php

use App\Models\Address;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\SoldUnit;
use App\Models\Stock;
use App\Models\User;
use App\Services\SoldUnitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tymon\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

// Helpers compartidos (supportSeed, clientToken, staffToken) viven en tests/Pest.php

// ───────────────────────── Servicio: generación desde orden ─────────────────────────

it('genera una unidad por cada ítem físico de la orden y es idempotente', function () {
    $seed = supportSeed(warrantyMonths: 24, orderQty: 3);

    $service = app(SoldUnitService::class);
    $first = $service->generateFromOrder($seed['order']);
    $second = $service->generateFromOrder($seed['order']); // reintento (webhook duplicado)

    expect($first)->toHaveCount(3);
    expect($second)->toHaveCount(0);
    expect(SoldUnit::where('order_id', $seed['order']->id)->count())->toBe(3);

    $unit = SoldUnit::where('order_id', $seed['order']->id)->first();
    expect($unit->client_id)->toBe($seed['client']->id);
    expect($unit->registration_source)->toBe('order');
    expect($unit->warranty_months)->toBe(24);
    expect($unit->warranty_expires_at->toDateString())
        ->toBe($unit->purchase_date->copy()->addMonths(24)->toDateString());
    expect($unit->warranty_active)->toBeTrue();
    expect($unit->warranty_status)->toBe('vigente');
});

it('no asigna garantía cuando el producto no tiene meses de garantía', function () {
    $seed = supportSeed(warrantyMonths: 0, orderQty: 1);

    app(SoldUnitService::class)->generateFromOrder($seed['order']);

    $unit = SoldUnit::where('order_id', $seed['order']->id)->first();
    expect($unit->warranty_expires_at)->toBeNull();
    expect($unit->warranty_active)->toBeFalse();
    expect($unit->warranty_status)->toBe('sin_garantia');
});

// ───────────────────────── Endpoints de cliente ─────────────────────────

it('lista las unidades del cliente autenticado', function () {
    $seed = supportSeed(orderQty: 2);
    app(SoldUnitService::class)->generateFromOrder($seed['order']);

    $res = $this->withHeader('Authorization', 'Bearer ' . clientToken($seed['client']))
        ->getJson('/api/client/units');

    $res->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(2, 'data');
});

it('registra una unidad manualmente con comprobante', function () {
    Storage::fake(config('filesystems.default'));
    $seed = supportSeed();

    $res = $this->withHeader('Authorization', 'Bearer ' . clientToken($seed['client']))
        ->postJson('/api/client/units', [
            'product_id' => $seed['product']->id,
            'serial_number' => 'SN-MANUAL-001',
            'purchase_date' => now()->subMonths(2)->toDateString(),
            'proof_file' => UploadedFile::fake()->image('comprobante.jpg'),
        ]);

    $res->assertCreated()
        ->assertJsonPath('data.serial_number', 'SN-MANUAL-001')
        ->assertJsonPath('data.registration_source', 'manual');

    $this->assertDatabaseHas('sold_units', [
        'client_id' => $seed['client']->id,
        'serial_number' => 'SN-MANUAL-001',
        'registration_source' => 'manual',
    ]);
});

it('devuelve la garantía de una unidad', function () {
    $seed = supportSeed(warrantyMonths: 12);
    app(SoldUnitService::class)->generateFromOrder($seed['order']);
    $unit = SoldUnit::where('order_id', $seed['order']->id)->first();

    $res = $this->withHeader('Authorization', 'Bearer ' . clientToken($seed['client']))
        ->getJson("/api/client/units/{$unit->id}/warranty");

    $res->assertOk()
        ->assertJsonPath('data.status', 'vigente')
        ->assertJsonPath('data.active', true);
});

it('impide ver la unidad de otro cliente', function () {
    $seed = supportSeed();
    app(SoldUnitService::class)->generateFromOrder($seed['order']);
    $unit = SoldUnit::where('order_id', $seed['order']->id)->first();

    $otherClient = Client::create([
        'name' => 'Otro',
        'email' => 'otro@example.com',
        'password' => bcrypt('secret123'),
        'client_type' => 'individual',
        'identity_document' => '99999999',
        'document_type' => 'DNI',
        'token_version' => '0',
        'email_verified_at' => now(),
    ]);

    $res = $this->withHeader('Authorization', 'Bearer ' . clientToken($otherClient))
        ->getJson("/api/client/units/{$unit->id}");

    $res->assertNotFound();
});

it('rechaza el acceso sin token', function () {
    $this->getJson('/api/client/units')->assertUnauthorized();
});

// ───────────────────────── Endpoints de staff ─────────────────────────

it('permite al staff buscar unidades por número de serie', function () {
    $seed = supportSeed();
    app(SoldUnitService::class)->generateFromOrder($seed['order']);
    $unit = SoldUnit::where('order_id', $seed['order']->id)->first();
    $unit->update(['serial_number' => 'SN-STAFF-777']);

    $res = $this->withHeader('Authorization', 'Bearer ' . staffToken($seed['user']))
        ->getJson('/api/support/units?serial_number=SN-STAFF-777');

    $res->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.serial_number', 'SN-STAFF-777');
});

it('permite al staff ver el detalle de una unidad', function () {
    $seed = supportSeed();
    app(SoldUnitService::class)->generateFromOrder($seed['order']);
    $unit = SoldUnit::where('order_id', $seed['order']->id)->first();

    $res = $this->withHeader('Authorization', 'Bearer ' . staffToken($seed['user']))
        ->getJson("/api/support/units/{$unit->id}");

    $res->assertOk()->assertJsonPath('data.id', $unit->id);
});
