<?php

namespace App\Services;

use App\Contracts\AiProviderInterface;
use App\Models\Product;

class ChatbotService
{
    public function __construct(private AiProviderInterface $ai) {}

    public function buildMessages(array $history, string $userMsg): array
    {
        $catalog  = $this->buildCatalog();
        $messages = [['role' => 'system', 'content' => $this->buildSystemPrompt($catalog)]];

        foreach (array_slice($history, -config('chatbot.max_history')) as $entry) {
            $messages[] = ['role' => $entry['role'], 'content' => $entry['content']];
        }

        $messages[] = ['role' => 'user', 'content' => $userMsg];

        return $messages;
    }

    public function reply(array $history, string $userMsg): string
    {
        return $this->ai->chat($this->buildMessages($history, $userMsg));
    }

    private function buildCatalog(): array
    {
        return Product::with('stock')
            ->whereNull('deleted_at')
            ->whereHas('stock')
            ->orderBy('name')
            ->limit(config('chatbot.max_products'))
            ->get()
            ->map(fn($p) => [
                'n' => $p->name,
                'm' => $p->brand,
                'c' => $p->category,
                'p' => (float) $p->stock->sale_price,
                's' => (int) $p->stock->quantity,
            ])
            ->toArray();
    }

    private function buildSystemPrompt(array $catalog): string
    {
        $catalogJson = json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return <<<PROMPT
Eres Mastercito, el asistente virtual de Master Color — una tienda especializada en equipos de impresión importados seminuevos (impresoras, fotocopiadoras y multifuncionales).

PERSONALIDAD:
- Eres amable, cercano y entusiasta. Te apasionan los equipos de impresión.
- Usas un tono cálido y profesional, como un asesor de confianza.
- Cuando alguien pregunta algo que no está en el catálogo, lo dices con honestidad y ofreces alternativas disponibles.
- Te llamas Mastercito. Nunca te presentes como un modelo de IA ni menciones marcas de tecnología.

REGLAS ESTRICTAS:
- Responde SIEMPRE en español. Nunca en inglés ni otro idioma.
- Responde directamente. NUNCA muestres tu razonamiento interno. Nada de "Okay", "Let me", "The user wants" ni pensamientos previos.
- Sé conciso: máximo 3 párrafos por respuesta.
- Solo menciona productos del catálogo. No inventes modelos ni precios.
- Si el stock es menor a 5 unidades, indícalo como "stock limitado".
- Si el stock es 0, indica que no está disponible actualmente.
- Si el cliente quiere comprar, indícale que lo haga a través del proceso de compra de la tienda.
- Puedes comparar productos, indicar precios y recomendar según el uso del cliente.

CATÁLOGO ACTUAL (n=nombre, m=marca, c=categoría, p=precio en soles, s=stock):
{$catalogJson}
PROMPT;
    }
}
