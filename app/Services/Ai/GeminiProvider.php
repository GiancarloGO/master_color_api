<?php

namespace App\Services\Ai;

use App\Contracts\AiProviderInterface;
use App\Contracts\ToolCallingProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiProvider implements AiProviderInterface, ToolCallingProviderInterface
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models';

    private ?string $apiKey;
    private array $models;
    private int $timeout;

    public function __construct()
    {
        $this->apiKey  = config('chatbot.providers.gemini.api_key');
        $this->models  = config('chatbot.providers.gemini.models');
        $this->timeout = config('chatbot.providers.gemini.timeout');
    }

    public function chat(array $messages): string
    {
        if (!$this->apiKey) {
            throw new \RuntimeException('GEMINI_APIKEY no está configurada.');
        }

        [$system, $contents] = $this->toGeminiFormat($messages);

        $lastError = null;

        foreach ($this->models as $model) {
            $start = microtime(true);

            $payload = [
                'contents'         => $contents,
                'generationConfig' => [
                    'maxOutputTokens' => 800,
                    'temperature'     => 0.7,
                ],
            ];

            if ($system !== '') {
                $payload['systemInstruction'] = ['parts' => [['text' => $system]]];
            }

            // El "thinking" consume tokens de salida y suma latencia: se reduce al mínimo.
            // Gemini 3.x usa niveles; 2.5 flash usa presupuesto (la serie 3.x rechaza 0 con 400).
            if ($thinking = $this->thinkingConfig($model)) {
                $payload['generationConfig']['thinkingConfig'] = $thinking;
            }

            try {
                $response = Http::withHeaders(['x-goog-api-key' => $this->apiKey])
                    ->timeout($this->timeout)
                    ->post(self::ENDPOINT . "/{$model}:generateContent", $payload);
            } catch (\Throwable $e) {
                Log::warning('Gemini request failed, trying next model', [
                    'model'      => $model,
                    'error'      => $e->getMessage(),
                    'total_time' => round(microtime(true) - $start, 2),
                ]);
                $lastError = "Error de conexión ({$model}): " . $e->getMessage();
                continue;
            }

            Log::info('Gemini response', [
                'status'     => $response->status(),
                'model'      => $model,
                'total_time' => round(microtime(true) - $start, 2),
            ]);

            if ($response->failed()) {
                Log::warning('Gemini request failed with HTTP status, trying next model', [
                    'model'  => $model,
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                $lastError = "Gemini error {$response->status()} ({$model}): " . $response->body();
                continue;
            }

            $reply = $this->extractText($response->json());

            if ($reply !== '') {
                return $reply;
            }

            Log::warning('Gemini returned empty response, trying next model', [
                'model'        => $model,
                'blockReason'  => $response->json('promptFeedback.blockReason'),
                'finishReason' => $response->json('candidates.0.finishReason'),
            ]);
            $lastError = "Gemini ({$model}) devolvió respuesta vacía.";
        }

        throw new \RuntimeException('Ningún modelo de Gemini pudo responder. Último error: ' . $lastError);
    }

    public function chatWithTools(array $messages, array $tools): array
    {
        if (!$this->apiKey) {
            throw new \RuntimeException('GEMINI_APIKEY no está configurada.');
        }

        [$system, $contents] = $this->toGeminiToolFormat($messages);
        $declarations = array_map(fn($t) => $t['function'], $tools);
        $lastError = null;

        foreach ($this->models as $model) {
            $payload = [
                'contents'         => $contents,
                'tools'            => [['functionDeclarations' => $declarations]],
                'generationConfig' => ['maxOutputTokens' => 800, 'temperature' => 0.2],
            ];
            if ($system !== '') {
                $payload['systemInstruction'] = ['parts' => [['text' => $system]]];
            }
            if ($thinking = $this->thinkingConfig($model)) {
                $payload['generationConfig']['thinkingConfig'] = $thinking;
            }

            try {
                // 429/503 suelen ser transitorios (límite por minuto, saturación): se reintenta antes de cambiar de modelo.
                $response = Http::withHeaders(['x-goog-api-key' => $this->apiKey])
                    ->timeout($this->timeout)
                    ->retry([2000, 4000], throw: false, when: fn($e, $req) => in_array($e->response?->status(), [429, 503], true))
                    ->post(self::ENDPOINT . "/{$model}:generateContent", $payload);
            } catch (\Throwable $e) {
                $lastError = "Error de conexión ({$model}): " . $e->getMessage();
                continue;
            }

            if ($response->failed()) {
                Log::warning('Gemini tools request failed, trying next model', ['model' => $model, 'status' => $response->status(), 'body' => $response->body()]);
                $lastError = "Gemini error {$response->status()} ({$model})";
                continue;
            }

            $parts = $response->json('candidates.0.content.parts') ?? [];
            // Un `args` vacío llega como [] y se reenviaría como lista (400): debe ser objeto.
            foreach ($parts as &$part) {
                if (isset($part['functionCall']) && empty($part['functionCall']['args'])) {
                    $part['functionCall']['args'] = (object) [];
                }
            }
            unset($part);
            $text = '';
            $calls = [];
            foreach ($parts as $part) {
                if (!empty($part['thought'])) continue;
                if (isset($part['functionCall'])) {
                    $calls[] = ['name' => (string) $part['functionCall']['name'], 'arguments' => (array) ($part['functionCall']['args'] ?? [])];
                } else {
                    $text .= $part['text'] ?? '';
                }
            }

            if ($calls === [] && trim($text) === '') {
                $lastError = "Gemini ({$model}) devolvió respuesta vacía.";
                continue;
            }

            // `provider_parts` conserva las partes originales (incluida la firma de pensamiento
            // que Gemini 3 exige al reenviar una llamada a función).
            return ['content' => trim($text), 'tool_calls' => $calls, 'provider_parts' => $parts];
        }

        throw new \RuntimeException('Ningún modelo de Gemini pudo responder. Último error: ' . $lastError);
    }

    /** @return array{0: string, 1: array<int, array<string, mixed>>} */
    private function toGeminiToolFormat(array $messages): array
    {
        $system = [];
        $contents = [];

        foreach ($messages as $m) {
            if ($m['role'] === 'system') { $system[] = $m['content']; continue; }

            if ($m['role'] === 'tool') {
                $part = ['functionResponse' => ['name' => $m['tool_name'], 'response' => ['result' => json_decode($m['content'], true)]]];
                $last = array_key_last($contents);
                if ($last !== null && $contents[$last]['role'] === 'user' && isset($contents[$last]['parts'][0]['functionResponse'])) {
                    $contents[$last]['parts'][] = $part;
                } else {
                    $contents[] = ['role' => 'user', 'parts' => [$part]];
                }
                continue;
            }

            if ($m['role'] === 'assistant' && isset($m['provider_parts'])) {
                $contents[] = ['role' => 'model', 'parts' => $m['provider_parts']];
                continue;
            }

            $role = $m['role'] === 'assistant' ? 'model' : 'user';
            if ($contents === [] && $role === 'model') continue;

            $last = array_key_last($contents);
            if ($last !== null && $contents[$last]['role'] === $role && isset($contents[$last]['parts'][0]['text'])) {
                $contents[$last]['parts'][0]['text'] .= "\n" . $m['content'];
                continue;
            }
            $contents[] = ['role' => $role, 'parts' => [['text' => $m['content']]]];
        }

        return [implode("\n\n", $system), $contents];
    }

    /** El nivel mínimo de "thinking" varía por modelo: 3.5 acepta "minimal"; 3.6+ exige al menos "low". */
    private function thinkingConfig(string $model): ?array
    {
        if (preg_match('/gemini-3\.(\d+)/', $model, $m) && (int) $m[1] >= 6) {
            return ['thinkingLevel' => 'low'];
        }
        if (str_contains($model, 'gemini-3')) {
            return ['thinkingLevel' => 'minimal'];
        }

        return str_contains($model, 'flash') ? ['thinkingBudget' => 0] : null;
    }

    /**
     * Convierte el formato OpenAI (system/user/assistant) al de Gemini:
     * el system va aparte y "assistant" pasa a "model". Gemini exige que la
     * conversación empiece por "user", así que se descartan respuestas iniciales
     * del modelo y se fusionan turnos consecutivos del mismo rol.
     *
     * @return array{0: string, 1: array<int, array{role: string, parts: array}>}
     */
    private function toGeminiFormat(array $messages): array
    {
        $system   = [];
        $contents = [];

        foreach ($messages as $m) {
            if ($m['role'] === 'system') {
                $system[] = $m['content'];
                continue;
            }

            $role = $m['role'] === 'assistant' ? 'model' : 'user';

            if ($contents === [] && $role === 'model') {
                continue;
            }

            $last = array_key_last($contents);
            if ($last !== null && $contents[$last]['role'] === $role) {
                $contents[$last]['parts'][0]['text'] .= "\n" . $m['content'];
                continue;
            }

            $contents[] = ['role' => $role, 'parts' => [['text' => $m['content']]]];
        }

        return [implode("\n\n", $system), $contents];
    }

    private function extractText(?array $json): string
    {
        $text = '';

        foreach ($json['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (!empty($part['thought'])) {
                continue;
            }
            $text .= $part['text'] ?? '';
        }

        return trim($text);
    }
}
