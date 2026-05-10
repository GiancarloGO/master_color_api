<?php

namespace App\Services\Ai;

use App\Contracts\AiProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenRouterProvider implements AiProviderInterface
{
    private string $apiKey;
    private array $models;
    private int $timeout;

    // Phrases reasoning models use when thinking out loud without <think> tags.
    private const THINKING_PREFIXES = [
        'okay,', 'ok,', 'let me', 'the user', 'i need to', 'i should',
        'first,', 'looking at', 'so,', 'well,', 'alright,',
    ];

    public function __construct()
    {
        $this->apiKey  = config('chatbot.providers.openrouter.api_key');
        $this->models  = config('chatbot.providers.openrouter.models');
        $this->timeout = config('chatbot.providers.openrouter.timeout');
    }

    public function chat(array $messages): string
    {
        $lastError = null;

        foreach ($this->models as $model) {
            $start = microtime(true);

            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'HTTP-Referer' => config('app.url'),
                    'X-Title'      => config('app.name'),
                ])
                ->timeout($this->timeout)
                ->post('https://openrouter.ai/api/v1/chat/completions', [
                    'model'       => $model,
                    'messages'    => $messages,
                    'max_tokens'  => 800,
                    'temperature' => 0.7,
                    'reasoning'   => ['exclude' => true],
                ]);

            $elapsed = round(microtime(true) - $start, 2);

            Log::info('OpenRouter response', [
                'status'     => $response->status(),
                'model'      => $model,
                'total_time' => $elapsed,
            ]);

            if ($response->status() === 429) {
                Log::warning('OpenRouter rate-limited, trying next model', ['model' => $model]);
                $lastError = 'OpenRouter error 429: ' . $response->body();
                continue;
            }

            if ($response->failed()) {
                throw new \RuntimeException('OpenRouter error ' . $response->status() . ': ' . $response->body());
            }

            $raw   = $response->json('choices.0.message.content') ?? '';
            $reply = $this->cleanResponse($raw);

            return $reply ?: throw new \RuntimeException("OpenRouter ({$model}) devolvió respuesta vacía.");
        }

        throw new \RuntimeException('Todos los modelos de OpenRouter están con rate limit: ' . $lastError);
    }

    private function cleanResponse(string $text): string
    {
        // Remove <think>...</think> blocks (some reasoning models use these tags).
        $text = preg_replace('/<think>.*?<\/think>/si', '', $text);

        // If the response starts with a thinking line, skip lines until we find
        // one that looks like a real answer (starts with a capital letter in Spanish
        // or a dash/bullet, not an English meta-commentary phrase).
        $lines   = explode("\n", $text);
        $output  = [];
        $started = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                if ($started) $output[] = '';
                continue;
            }

            if (!$started && $this->isThinkingLine($trimmed)) {
                continue;
            }

            $started  = true;
            $output[] = $line;
        }

        return trim(implode("\n", $output));
    }

    private function isThinkingLine(string $line): bool
    {
        $lower = mb_strtolower($line);
        foreach (self::THINKING_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
