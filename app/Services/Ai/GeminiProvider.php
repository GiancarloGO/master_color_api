<?php

namespace App\Services\Ai;

use App\Contracts\AiProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiProvider implements AiProviderInterface
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
            if (str_contains($model, 'gemini-3')) {
                $payload['generationConfig']['thinkingConfig'] = ['thinkingLevel' => 'minimal'];
            } elseif (str_contains($model, 'flash')) {
                $payload['generationConfig']['thinkingConfig'] = ['thinkingBudget' => 0];
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
