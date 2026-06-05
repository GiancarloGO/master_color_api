<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Helpers compartidos de soporte (unidades / tickets)
|--------------------------------------------------------------------------
*/

/**
 * Siembra usuario(staff), cliente, producto con stock y una orden con un detalle.
 *
 * @return array{user: \App\Models\User, client: \App\Models\Client, product: \App\Models\Product, order: \App\Models\Order}
 */
function supportSeed(int $warrantyMonths = 24, int $orderQty = 3): array
{
    $role = \App\Models\Role::create(['name' => 'admin', 'description' => 'Administrador']);
    $user = \App\Models\User::factory()->create([
        'role_id' => $role->id,
        'dni' => '8' . random_int(1000000, 9999999),
        'token_version' => '0',
    ]);

    $client = \App\Models\Client::create([
        'name' => 'Cliente Soporte',
        'email' => 'soporte.' . uniqid() . '@example.com',
        'password' => bcrypt('secret123'),
        'client_type' => 'individual',
        'identity_document' => (string) random_int(10000000, 99999999),
        'document_type' => 'DNI',
        'token_version' => '0',
        'email_verified_at' => now(),
    ]);

    $address = \App\Models\Address::create([
        'client_id' => $client->id,
        'address_full' => 'Av. Siempre Viva 123',
        'district' => 'Centro',
        'province' => 'Lima',
        'department' => 'Lima',
        'postal_code' => '15001',
        'reference' => 'Ref',
        'is_main' => true,
    ]);

    $product = \App\Models\Product::create([
        'name' => 'Máquina Tintométrica MC-200',
        'sku' => 'SKU-' . uniqid(),
        'image_url' => 'http://example.com/img.png',
        'barcode' => 'BAR-' . uniqid(),
        'brand' => 'MasterColor',
        'description' => 'Equipo serializado',
        'presentation' => 'Unidad',
        'category' => 'Equipos',
        'unidad' => 'unidad',
        'default_warranty_months' => $warrantyMonths,
        'user_id' => $user->id,
    ]);

    \App\Models\Stock::create([
        'product_id' => $product->id,
        'quantity' => 100,
        'min_stock' => 0,
        'max_stock' => 1000,
        'purchase_price' => 500.00,
        'sale_price' => 1200.00,
    ]);

    $order = \App\Models\Order::create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'delivery_address_id' => $address->id,
        'subtotal' => 1200.00 * $orderQty,
        'shipping_cost' => 0,
        'discount' => 0,
        'status' => 'pendiente',
    ]);

    $order->orderDetails()->create([
        'product_id' => $product->id,
        'quantity' => $orderQty,
        'unit_price' => 1200.00,
        'subtotal' => 1200.00 * $orderQty,
    ]);

    return ['user' => $user, 'client' => $client, 'product' => $product, 'order' => $order->fresh('orderDetails')];
}

function clientToken(\App\Models\Client $client): string
{
    return \Tymon\JWTAuth\Facades\JWTAuth::claims(['type' => 'client', 'token_version' => $client->token_version])->fromUser($client);
}

function staffToken(\App\Models\User $user): string
{
    return \Tymon\JWTAuth\Facades\JWTAuth::claims(['token_version' => $user->token_version])->fromUser($user);
}
