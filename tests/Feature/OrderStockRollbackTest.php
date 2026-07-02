<?php

use App\Models\Address;
use App\Models\Client;
use App\Models\DetailMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Construye un escenario base: usuario, cliente, dirección, producto con stock
 * y una orden con un detalle. Devuelve [order, stock].
 *
 * @return array{0: Order, 1: Stock}
 */
function makeOrderWithStock(int $initialQty = 100, int $orderQty = 10): array
{
    $role = Role::create([
        'name' => 'admin',
        'description' => 'Administrador',
    ]);

    $user = User::factory()->create([
        'role_id' => $role->id,
        'dni' => '87654321',
    ]);

    $client = Client::create([
        'name' => 'Cliente Test',
        'email' => 'cliente.test@example.com',
        'password' => bcrypt('secret123'),
        'client_type' => 'individual',
        'identity_document' => '12345678',
        'document_type' => 'DNI',
    ]);

    $address = Address::create([
        'client_id' => $client->id,
        'address_full' => 'Av. Siempre Viva 123',
        'district' => 'Centro',
        'province' => 'Lima',
        'department' => 'Lima',
        'postal_code' => '15001',
        'reference' => 'Frente al parque',
        'is_main' => true,
    ]);

    $product = Product::create([
        'name' => 'Pintura Roja',
        'sku' => 'SKU-001',
        'image_url' => 'http://example.com/img.png',
        'barcode' => 'BAR-001',
        'brand' => 'MasterColor',
        'description' => 'Pintura de prueba',
        'presentation' => 'Galón',
        'category' => 'Pinturas',
        'unidad' => 'unidad',
        'user_id' => $user->id,
    ]);

    $stock = Stock::create([
        'product_id' => $product->id,
        'quantity' => $initialQty,
        'min_stock' => 0,
        'max_stock' => 1000,
        'purchase_price' => 10.00,
        'sale_price' => 20.00,
    ]);

    $order = Order::create([
        'user_id' => $user->id,
        'client_id' => $client->id,
        'delivery_address_id' => $address->id,
        'subtotal' => 20.00 * $orderQty,
        'shipping_cost' => 0,
        'discount' => 0,
        'status' => 'pendiente',
    ]);

    $order->orderDetails()->create([
        'product_id' => $product->id,
        'quantity' => $orderQty,
        'unit_price' => 20.00,
        'subtotal' => 20.00 * $orderQty,
    ]);

    // Las acciones de stock registran auditoría con el usuario autenticado.
    test()->actingAs($user);

    return [$order->fresh('orderDetails'), $stock];
}

it('descuenta el stock una sola vez aunque se procese la orden varias veces', function () {
    [$order, $stock] = makeOrderWithStock(initialQty: 100, orderQty: 10);

    $service = app(StockMovementService::class);

    // Simula webhooks 'approved' repetidos de MercadoPago.
    $service->processOrderStockReduction($order);
    $service->processOrderStockReduction($order);
    $service->processOrderStockReduction($order);

    expect($stock->fresh()->quantity)->toBe(90);

    $salidas = StockMovement::where('voucher_number', 'LIKE', "VENTA-{$order->id}-%")
        ->where('movement_type', 'salida')
        ->count();

    expect($salidas)->toBe(1);
});

it('devuelve todo el stock descontado al anular el pedido', function () {
    [$order, $stock] = makeOrderWithStock(initialQty: 100, orderQty: 10);

    app(StockMovementService::class)->processOrderStockReduction($order);
    expect($stock->fresh()->quantity)->toBe(90);

    app(PaymentService::class)->rollbackOrderStock($order, 'Cancelación de prueba');

    // Stock restaurado por completo.
    expect($stock->fresh()->quantity)->toBe(100);

    // El movimiento de venta queda marcado como cancelado.
    $salidaActiva = StockMovement::where('voucher_number', 'LIKE', "VENTA-{$order->id}-%")
        ->where('movement_type', 'salida')
        ->whereNull('canceled_at')
        ->exists();

    expect($salidaActiva)->toBeFalse();
});

it('revierte TODOS los movimientos de venta cuando hubo un descuento duplicado heredado', function () {
    [$order, $stock] = makeOrderWithStock(initialQty: 100, orderQty: 10);
    $orderDetail = $order->orderDetails->first();

    // Simula datos previos al fix: dos movimientos 'salida' que descontaron 10 c/u (total 20).
    foreach ([90, 80] as $newQty) {
        $previous = $newQty + 10;
        $movement = StockMovement::create([
            'movement_type' => 'salida',
            'reason' => "VENTA - Orden #{$order->id} (duplicado heredado)",
            'voucher_number' => "VENTA-{$order->id}-" . now()->format('YmdHis') . '-' . $newQty,
            'user_id' => $order->user_id,
        ]);

        DetailMovement::create([
            'stock_movement_id' => $movement->id,
            'stock_id' => $stock->id,
            'quantity' => $orderDetail->quantity,
            'unit_price' => $orderDetail->unit_price,
            'previous_stock' => $previous,
            'new_stock' => $newQty,
        ]);
    }

    // Refleja el doble descuento en el stock real.
    $stock->update(['quantity' => 80]);

    app(PaymentService::class)->rollbackOrderStock($order, 'Cancelación con duplicados');

    // Ambos descuentos (20) se devuelven: 80 + 20 = 100.
    expect($stock->fresh()->quantity)->toBe(100);

    $salidasActivas = StockMovement::where('voucher_number', 'LIKE', "VENTA-{$order->id}-%")
        ->where('movement_type', 'salida')
        ->whereNull('canceled_at')
        ->count();

    expect($salidasActivas)->toBe(0);
});

it('no falla al anular un pedido que nunca descontó stock', function () {
    [$order, $stock] = makeOrderWithStock(initialQty: 100, orderQty: 10);

    app(PaymentService::class)->rollbackOrderStock($order, 'Cancelado en pendiente_pago');

    // El stock no se altera y no se lanza excepción.
    expect($stock->fresh()->quantity)->toBe(100);
});

it('enlaza el movimiento de venta con la orden mediante order_id', function () {
    [$order, $stock] = makeOrderWithStock(initialQty: 100, orderQty: 10);

    $movement = app(StockMovementService::class)->processOrderStockReduction($order);

    expect($movement->order_id)->toBe($order->id);
});

it('usa order_id para la idempotencia aunque cambie el patrón del voucher', function () {
    [$order, $stock] = makeOrderWithStock(initialQty: 100, orderQty: 10);

    // Primer descuento por la vía normal.
    app(StockMovementService::class)->processOrderStockReduction($order);
    expect($stock->fresh()->quantity)->toBe(90);

    // Segundo intento: aunque el voucher tuviera otro formato, order_id evita
    // el doble descuento.
    app(StockMovementService::class)->processOrderStockReduction($order);

    expect($stock->fresh()->quantity)->toBe(90);
    expect(StockMovement::where('order_id', $order->id)->where('movement_type', 'salida')->whereNull('canceled_at')->count())->toBe(1);
});

it('no vuelve a devolver stock si el pedido ya fue anulado (doble anulación)', function () {
    [$order, $stock] = makeOrderWithStock(initialQty: 100, orderQty: 10);

    app(StockMovementService::class)->processOrderStockReduction($order);
    expect($stock->fresh()->quantity)->toBe(90);

    $payment = app(PaymentService::class);
    $payment->rollbackOrderStock($order, 'Primera anulación');
    expect($stock->fresh()->quantity)->toBe(100);

    // Segunda anulación: no debe volver a incrementar el stock ni fallar.
    $payment->rollbackOrderStock($order, 'Segunda anulación (idempotente)');
    expect($stock->fresh()->quantity)->toBe(100);
});

it('revierte la orden si la devolución de stock falla (no queda cancelada)', function () {
    [$order, $stock] = makeOrderWithStock(initialQty: 100, orderQty: 10);

    app(StockMovementService::class)->processOrderStockReduction($order);
    expect($stock->fresh()->quantity)->toBe(90);

    // Fuerza un fallo dentro del rollback (p. ej. movimiento ya cancelado por una
    // carrera) inyectando un StockMovementService que lanza al cancelar.
    $boom = new class extends StockMovementService {
        public function __construct() {}
        public function cancelMovement(StockMovement $movement): StockMovement
        {
            throw new \RuntimeException('Fallo simulado al devolver stock');
        }
    };
    app()->instance(StockMovementService::class, $boom);

    // El controlador envuelve la anulación en una transacción: si el rollback de
    // stock falla, la excepción se propaga y la orden NO debe quedar cancelada.
    $threw = false;
    try {
        \Illuminate\Support\Facades\DB::transaction(function () use ($order) {
            $order->update(['status' => 'cancelado']);
            app(PaymentService::class)->rollbackOrderStock($order, 'Anulación con fallo');
        });
    } catch (\Throwable $e) {
        $threw = true;
    }

    expect($threw)->toBeTrue();
    // La orden sigue en su estado previo y el stock no cambió.
    expect($order->fresh()->status)->not->toBe('cancelado');
    expect($stock->fresh()->quantity)->toBe(90);
});
