<?php

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeAdminToken(): array
{
    $role = Role::firstOrCreate(['name' => 'Admin'], ['description' => 'Administrador']);
    $user = User::factory()->create([
        'role_id' => $role->id,
        'dni' => '8' . random_int(1000000, 9999999),
        'token_version' => '0',
    ]);

    return [$user, staffToken($user)];
}

it('crea producto solo con los campos obligatorios y deja opcionales en null', function () {
    [$admin, $token] = makeAdminToken();

    $response = $this->withToken($token)->postJson('/api/products', [
        'name' => 'Tóner genérico sin barcode',
        'sku' => 'SKU-MIN-' . uniqid(),
        'category' => 'toners',
        'unidad' => 'unidad',
        'purchase_price' => 45.50,
        'sale_price' => 89.90,
    ]);

    $response->assertStatus(201);

    $product = Product::with('stock')->findOrFail($response->json('data.id'));
    expect($product->barcode)->toBeNull();
    expect($product->brand)->toBeNull();
    expect($product->presentation)->toBeNull();
    expect($product->description)->toBeNull();
    expect($product->getRawOriginal('image_url'))->toBeNull();
    expect((float) $product->stock->purchase_price)->toBe(45.50);
    expect((float) $product->stock->sale_price)->toBe(89.90);

    $audit = AuditLog::where('entity_type', 'Product')
        ->where('entity_id', $product->id)
        ->where('action', 'product.created')
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->actor_type)->toBe('staff');
    expect($audit->actor_id)->toBe($admin->id);
    expect($audit->actor_name)->toBe($admin->name);
    expect($audit->created_at)->not->toBeNull();
    expect($audit->new_values['name'])->toBe($product->name);
    expect($audit->new_values['sku'])->toBe($product->sku);
});

it('permite múltiples productos con barcode null sin conflicto unique', function () {
    [, $token] = makeAdminToken();

    foreach (['Producto A sin barcode', 'Producto B sin barcode'] as $name) {
        $this->withToken($token)->postJson('/api/products', [
            'name' => $name,
            'sku' => 'SKU-' . uniqid(),
            'category' => 'repuestos',
            'unidad' => 'unidad',
            'purchase_price' => 10,
            'sale_price' => 25,
        ])->assertStatus(201);
    }

    expect(Product::whereNull('barcode')->count())->toBe(2);
});

it('rechaza creación cuando faltan campos obligatorios o hay incoherencia de precios/stock', function () {
    [, $token] = makeAdminToken();

    // Faltan obligatorios (incluye category)
    $this->withToken($token)->postJson('/api/products', [
        'name' => 'Incompleto',
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['sku', 'category', 'unidad', 'purchase_price', 'sale_price']);

    // Precio de venta menor o igual al de compra
    $this->withToken($token)->postJson('/api/products', [
        'name' => 'Precio incoherente',
        'sku' => 'SKU-' . uniqid(),
        'category' => 'tintas',
        'unidad' => 'unidad',
        'purchase_price' => 100,
        'sale_price' => 90,
    ])->assertStatus(422)->assertJsonValidationErrors(['sale_price']);

    // Stock máximo <= mínimo
    $this->withToken($token)->postJson('/api/products', [
        'name' => 'Stock incoherente',
        'sku' => 'SKU-' . uniqid(),
        'category' => 'tintas',
        'unidad' => 'unidad',
        'purchase_price' => 50,
        'sale_price' => 80,
        'quantity' => 10,
        'min_stock' => 10,
        'max_stock' => 5,
    ])->assertStatus(422)->assertJsonValidationErrors(['max_stock']);

    // Cantidad inicial menor al stock mínimo
    $this->withToken($token)->postJson('/api/products', [
        'name' => 'Cantidad menor a mínimo',
        'sku' => 'SKU-' . uniqid(),
        'category' => 'tintas',
        'unidad' => 'unidad',
        'purchase_price' => 50,
        'sale_price' => 80,
        'quantity' => 2,
        'min_stock' => 5,
        'max_stock' => 20,
    ])->assertStatus(422)->assertJsonValidationErrors(['quantity']);
});

it('registra en auditoría quién, cuándo y qué cambió al editar y eliminar un producto', function () {
    [$creator, $creatorToken] = makeAdminToken();
    [$editor, $editorToken] = makeAdminToken();

    $sku = 'SKU-AUD-' . uniqid();
    $created = $this->withToken($creatorToken)->postJson('/api/products', [
        'name' => 'Impresora Original',
        'sku' => $sku,
        'category' => 'impresoras',
        'unidad' => 'unidad',
        'purchase_price' => 1000,
        'sale_price' => 1500,
        'min_stock' => 1,
        'max_stock' => 10,
        'quantity' => 2,
    ])->assertStatus(201);

    $productId = $created->json('data.id');

    $this->withToken($editorToken)->postJson("/api/products/updateProduct/{$productId}", [
        'name' => 'Impresora Editada',
        'sku' => $sku,
        'category' => 'impresoras',
        'unidad' => 'unidad',
        'sale_price' => 1650,
    ])->assertStatus(200);

    $updateAudit = AuditLog::where('entity_type', 'Product')
        ->where('entity_id', $productId)
        ->where('action', 'product.updated')
        ->first();

    expect($updateAudit)->not->toBeNull();
    expect($updateAudit->actor_id)->toBe($editor->id);
    expect($updateAudit->actor_name)->toBe($editor->name);
    expect($updateAudit->created_at)->not->toBeNull();
    expect($updateAudit->old_values)->toBe(['name' => 'Impresora Original', 'sale_price' => 1500]);
    expect($updateAudit->new_values)->toBe(['name' => 'Impresora Editada', 'sale_price' => 1650]);

    $this->withToken($editorToken)->deleteJson("/api/products/{$productId}")->assertStatus(200);

    $deleteAudit = AuditLog::where('entity_type', 'Product')
        ->where('entity_id', $productId)
        ->where('action', 'product.deleted')
        ->first();

    expect($deleteAudit)->not->toBeNull();
    expect($deleteAudit->actor_id)->toBe($editor->id);
    expect($deleteAudit->old_values['name'])->toBe('Impresora Editada');
});
