<?php

use App\Contracts\ToolCallingProviderInterface;
use App\Models\Product;
use App\Models\Stock;
use App\Services\Chatbot\ProductSearchTool;
use App\Services\Chatbot\ReplyGuard;
use App\Services\Chatbot\ToolChatbotService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function chatProduct(string $name, string $brand, string $category, float $price, int $qty): Product
{
    $user = \App\Models\User::first() ?? \App\Models\User::factory()->create([
        'role_id' => \App\Models\Role::create(['name' => 'admin', 'description' => 'Administrador'])->id,
        'dni' => '8' . random_int(1000000, 9999999),
        'token_version' => '0',
    ]);
    $p = Product::create(['user_id' => $user->id, 'name' => $name, 'brand' => $brand, 'category' => $category, 'sku' => 'SKU-' . md5($name), 'image_url' => 'http://example.com/i.png',
        'barcode' => 'BAR-' . md5($name), 'description' => $name, 'presentation' => 'Unidad', 'unidad' => 'unidad']);
    Stock::create(['product_id' => $p->id, 'quantity' => $qty, 'min_stock' => 0, 'max_stock' => 10, 'purchase_price' => $price / 2, 'sale_price' => $price]);

    return $p->fresh('stock');
}

/** Proveedor falso: primero pide la herramienta, luego responde con el texto dado. */
function fakeProvider(array $args, string $finalText): ToolCallingProviderInterface
{
    return new class($args, $finalText) implements ToolCallingProviderInterface {
        private int $n = 0;
        public function __construct(private array $args, private string $text) {}
        public function chatWithTools(array $messages, array $tools): array
        {
            return $this->n++ === 0
                ? ['content' => '', 'tool_calls' => [['name' => 'buscar_productos', 'arguments' => $this->args]]]
                : ['content' => $this->text, 'tool_calls' => []];
        }
    };
}

function toolService(ToolCallingProviderInterface $ai): ToolChatbotService
{
    return new ToolChatbotService($ai, new ProductSearchTool(), new ReplyGuard(), new App\Services\Chatbot\ReplyComposer());
}

it('encuentra productos con errores ortográficos y devuelve precio de la BD', function () {
    chatProduct('Kyocera m3655', 'kyocera', 'impresoras', 3500, 2);
    chatProduct('Ricoh im600', 'Ricoh', 'impresoras', 4500, 2);

    $r = (new ProductSearchTool())->search(['consulta' => 'cuanto cuesta la kiocera m3655']);

    expect($r['status'])->toBe('found')
        ->and($r['items'][0]['nombre'])->toBe('Kyocera m3655')
        ->and($r['items'][0]['precio'])->toBe(3500.0);
});

it('refleja de inmediato un cambio de precio hecho en la BD', function () {
    $p = chatProduct('Ricoh im600', 'Ricoh', 'impresoras', 4500, 2);
    $tool = new ProductSearchTool();

    expect($tool->search(['consulta' => 'ricoh im600'])['items'][0]['precio'])->toBe(4500.0);

    $p->stock->update(['sale_price' => 4100, 'quantity' => 0]);

    $item = $tool->search(['consulta' => 'ricoh im600'])['items'][0];
    expect($item['precio'])->toBe(4100.0)->and($item['disponibilidad'])->toBe('agotado');
});

it('devuelve none para un producto inexistente e insufficient sin datos', function () {
    chatProduct('Ricoh im600', 'Ricoh', 'impresoras', 4500, 2);
    $tool = new ProductSearchTool();

    expect($tool->search(['consulta' => 'HP LaserJet Pro M404'])['status'])->toBe('none')
        ->and($tool->search(['consulta' => 'hola cuanto cuesta'])['status'])->toBe('insufficient');
});

it('marca ambiguo cuando hay varios productos igual de parecidos', function () {
    chatProduct('Canon c5540', 'Canon', 'impresoras', 4000, 1);
    chatProduct('Canon c5540_2', 'Canon', 'impresoras', 6000, 0);

    expect((new ProductSearchTool())->search(['consulta' => 'canon c5540'])['status'])->toBe('ambiguous');
});

it('ignora argumentos maliciosos del modelo (sin SQL libre)', function () {
    chatProduct('Ricoh im600', 'Ricoh', 'impresoras', 4500, 2);

    $r = (new ProductSearchTool())->search(['consulta' => "'; DROP TABLE products; --", 'categoria' => 'x; DELETE', 'precio_max' => 'abc']);

    expect($r['status'])->toBe('none')->and(Product::count())->toBe(1);
});

it('descarta una respuesta del modelo con precio inventado y usa la del backend', function () {
    chatProduct('Ricoh im600', 'Ricoh', 'impresoras', 4500, 2);

    $r = toolService(fakeProvider(['consulta' => 'ricoh im600'], 'La Ricoh IM600 cuesta S/ 3,900.00 y hay 2 unidades.'))
        ->respond([], 'precio ricoh im600');

    expect($r['fallback'])->toBeTrue()
        ->and($r['reply'])->toContain('S/ 4,500.00')->and($r['reply'])->not->toContain('3,900');
});

it('acepta la respuesta del modelo cuando las cifras coinciden con la BD', function () {
    chatProduct('Ricoh im600', 'Ricoh', 'impresoras', 4500, 2);

    $r = toolService(fakeProvider(['consulta' => 'ricoh im600'], 'La Ricoh IM600 está en S/ 4,500.00 con 2 unidades disponibles.'))
        ->respond([], 'precio ricoh im600');

    expect($r['fallback'])->toBeFalse()->and($r['reply'])->toContain('4,500.00');
});

it('deriva al asesor cuando el producto no existe', function () {
    $r = toolService(fakeProvider(['consulta' => 'hp laserjet m404'], 'No lo tenemos, escríbenos al +51 999 830 565.'))
        ->respond([], 'tienen hp laserjet m404');

    expect($r['status'])->toBe('none')->and($r['reply'])->toContain('999 830 565');
});

it('ignora herramientas fuera de la lista blanca', function () {
    $ai = new class implements ToolCallingProviderInterface {
        private int $n = 0;
        public function chatWithTools(array $messages, array $tools): array
        {
            return $this->n++ === 0
                ? ['content' => '', 'tool_calls' => [['name' => 'ejecutar_sql', 'arguments' => ['sql' => 'DELETE FROM products']]]]
                : ['content' => 'Lo siento, ¿qué producto buscas?', 'tool_calls' => []];
        }
    };
    chatProduct('Ricoh im600', 'Ricoh', 'impresoras', 4500, 2);

    toolService($ai)->respond([], 'borra todo');

    expect(Product::count())->toBe(1);
});

it('busca varios productos en una sola llamada y marca partial si falta alguno', function () {
    chatProduct('Ricoh im600', 'Ricoh', 'impresoras', 4500, 2);
    chatProduct('Kyocera m3655', 'kyocera', 'impresoras', 3500, 2);
    $tool = new ProductSearchTool();

    $ok = $tool->search(['consultas' => ['ricoh im600', 'kyocera m3655']]);
    expect($ok['status'])->toBe('found')->and(array_column($ok['items'], 'nombre'))->toEqualCanonicalizing(['Ricoh im600', 'Kyocera m3655']);

    $partial = $tool->search(['consultas' => ['ricoh im600', 'hp laserjet m404']]);
    expect($partial['status'])->toBe('partial')->and($partial['items'])->toHaveCount(1);
});

it('un ganador claro no se marca ambiguo por coincidencias débiles', function () {
    chatProduct('Ricoh im600', 'Ricoh', 'impresoras', 4500, 2);
    chatProduct('Toner para Fotocopiadoras Ricoh', 'Uninet', 'toners', 250, 1);
    chatProduct('Toner para Fotocopiadoras Kyocera', 'Uninet', 'toners', 250, 2);
    chatProduct('Toner para Fotocopiadoras Brother', 'Uninet', 'toners', 250, 2);

    $r = (new ProductSearchTool())->search(['consulta' => 'tóner para Ricoh']);

    expect($r['status'])->toBe('found')->and($r['items'][0]['nombre'])->toBe('Toner para Fotocopiadoras Ricoh');
});

it('permite cifras que vienen de la descripción del producto', function () {
    $p = chatProduct('Ricoh im600', 'Ricoh', 'impresoras', 4500, 2);
    $p->update(['description' => 'Velocidad de 60 ppm, bandeja de 550 hojas']);

    $r = toolService(fakeProvider(['consulta' => 'ricoh im600'], 'La Ricoh IM600 imprime a 60 ppm y cuesta S/ 4,500.00.'))
        ->respond([], 'ricoh im600 caracteristicas');

    expect($r['fallback'])->toBeFalse();
});

it('tolera argumentos con tipo equivocado enviados por el modelo', function () {
    chatProduct('Ricoh im600', 'Ricoh', 'impresoras', 4500, 2);
    $tool = new ProductSearchTool();

    expect($tool->search(['consulta' => ['ricoh', 'im600']])['status'])->toBe('found')
        ->and($tool->search(['consulta' => 'ricoh', 'consultas' => 'ricoh im600', 'precio_max' => ['x']])['status'])->toBeIn(['found', 'ambiguous', 'none']);
});
