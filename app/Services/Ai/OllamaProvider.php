<?php

namespace App\Services\Ai;

use App\Contracts\AiProviderInterface;
use Illuminate\Support\Facades\Log;

class OllamaProvider implements AiProviderInterface
{
    private string $url;
    private string $model;
    private int $timeout;

    public function __construct()
    {
        $this->url     = config('chatbot.providers.ollama.url');
        $this->model   = config('chatbot.providers.ollama.model');
        $this->timeout = config('chatbot.providers.ollama.timeout');
    }

    public function chat(array $messages): string
    {
        $payload = json_encode([
            'model'    => $this->model,
            'messages' => $messages,
            'stream'   => true,
            'options'  => [
                'num_ctx'     => 2048,
                'num_predict' => 300,
                'temperature' => 0.7,
            ],
        ]);

        $ch = curl_init($this->url . '/api/chat');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_RETURNTRANSFER => true,
        ]);

        $raw      = curl_exec($ch);
        $error    = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        curl_close($ch);

        Log::info('Ollama response', [
            'http_code'  => $httpCode,
            'total_time' => $totalTime,
            'error'      => $error,
        ]);

        if ($error) {
            throw new \RuntimeException("Ollama cURL error: {$error}");
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

        return trim($reply) ?: throw new \RuntimeException('Ollama devolvió respuesta vacía.');
    }
}
