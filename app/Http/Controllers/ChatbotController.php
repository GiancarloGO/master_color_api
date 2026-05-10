<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChatbotRequest;
use App\Models\ChatLog;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatbotController extends Controller
{
    public function message(ChatbotRequest $request)
    {
        $data      = $request->validated();
        $userMsg   = $data['message'];
        $history   = $data['history'] ?? [];
        $sessionId = $data['session_id'];

        $catalog = $this->buildCatalog();
        $system  = $this->buildSystemPrompt($catalog);
        $messages = $this->buildMessages($system, $history, $userMsg);

        ChatLog::create([
            'session_id' => $sessionId,
            'role'       => 'user',
            'message'    => $userMsg,
            'ip_address' => $request->ip(),
        ]);

        try {
            $reply = $this->callOllamaStreaming($messages);

            ChatLog::create([
                'session_id' => $sessionId,
                'role'       => 'assistant',
                'message'    => $reply,
                'ip_address' => $request->ip(),
            ]);

            return response()->json([
                'success' => true,
                'reply'   => $reply,
            ]);
        } catch (\Exception $e) {
            Log::error('Ollama no disponible', ['error' => $e->getMessage()]);
            return $this->ollamaUnavailable();
        }
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
Eres el asistente virtual de Master Color, una tienda especializada en equipos de impresión (impresoras, fotocopiadoras y multifuncionales, principalmente equipos importados seminuevos).

REGLAS ESTRICTAS:
- Responde SIEMPRE en español.
- Sé conciso: máximo 3 párrafos por respuesta.
- Solo menciona productos que aparezcan en el catálogo. No inventes modelos ni precios.
- Si el stock de un producto es menor a 5 unidades, indícalo como "stock limitado".
- Si el stock es 0, indica que el producto no está disponible actualmente.
- Si el cliente quiere hacer un pedido o comprar, indícale que lo haga a través del proceso de compra de la tienda.
- Puedes comparar productos, indicar precios, recomendar según el uso y explicar diferencias entre marcas.
- Si preguntan por algo que no está en el catálogo, responde honestamente que no tenemos ese producto disponible.

CATÁLOGO ACTUAL (n=nombre, m=marca, c=categoría, p=precio en soles, s=stock):
{$catalogJson}
PROMPT;
    }

    private function buildMessages(string $system, array $history, string $userMsg): array
    {
        $messages = [['role' => 'system', 'content' => $system]];

        $recent = array_slice($history, -config('chatbot.max_history'));
        foreach ($recent as $entry) {
            $messages[] = ['role' => $entry['role'], 'content' => $entry['content']];
        }

        $messages[] = ['role' => 'user', 'content' => $userMsg];

        return $messages;
    }

    private function callOllamaStreaming(array $messages): string
    {
        $payload = json_encode([
            'model'    => config('chatbot.model'),
            'messages' => $messages,
            'stream'   => true,
            'options'  => [
                'num_ctx'     => 2048,
                'num_predict' => 300,
                'temperature' => 0.7,
            ],
        ]);

        $ch = curl_init(config('chatbot.ollama_url') . '/api/chat');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => config('chatbot.timeout'),
            CURLOPT_RETURNTRANSFER => true,
        ]);

        $raw      = curl_exec($ch);
        $error    = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $totalTime= curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        curl_close($ch);

        Log::info('Ollama cURL result', [
            'http_code'  => $httpCode,
            'total_time' => $totalTime,
            'raw_len'    => strlen((string) $raw),
            'error'      => $error,
        ]);

        if ($error) {
            throw new \RuntimeException("cURL error: {$error}");
        }

        $reply = '';
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if (!$line) continue;
            $data = json_decode($line, true);
            if (isset($data['message']['content'])) {
                $reply .= $data['message']['content'];
            }
        }

        return trim($reply) ?: 'No pude generar una respuesta. Por favor intenta de nuevo.';
    }

    private function ollamaUnavailable(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => false,
            'reply'   => 'Lo siento, el asistente no está disponible en este momento. Por favor intenta en unos minutos o contáctanos directamente.',
        ], 503);
    }
}
