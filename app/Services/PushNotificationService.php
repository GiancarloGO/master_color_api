<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class PushNotificationService
{
    /**
     * Envía una notificación push a todos los dispositivos de un modelo
     * (Client o User) que tenga la relación `deviceTokens`.
     */
    public function sendToModel(Model $notifiable, string $title, string $body, array $data = []): void
    {
        if (!method_exists($notifiable, 'deviceTokens')) {
            return;
        }

        $tokens = $notifiable->deviceTokens()->pluck('token')->all();

        $this->sendToTokens($tokens, $title, $body, $data);
    }

    /**
     * Envía a una lista de tokens FCM. Degrada con gracia si FCM no está
     * configurado o no hay tokens (no lanza excepción).
     *
     * @param  string[]  $tokens
     */
    public function sendToTokens(array $tokens, string $title, string $body, array $data = []): void
    {
        $tokens = array_values(array_filter($tokens));

        if (empty($tokens)) {
            return;
        }

        $serverKey = config('services.fcm.key');

        if (empty($serverKey)) {
            Log::info('Push notification skipped: FCM not configured', [
                'title' => $title,
                'tokens' => count($tokens),
            ]);

            return;
        }

        foreach ($tokens as $token) {
            try {
                $this->dispatchToFcm($serverKey, $token, $title, $body, $data);
            } catch (\Throwable $e) {
                Log::error('Push notification failed', [
                    'token' => substr($token, 0, 12) . '...',
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Llamada HTTP real a FCM (legacy HTTP API). Aislada para poder simularse en tests.
     */
    protected function dispatchToFcm(string $serverKey, string $token, string $title, string $body, array $data): void
    {
        $payload = [
            'to' => $token,
            'notification' => [
                'title' => $title,
                'body' => $body,
            ],
            'data' => $data,
        ];

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => 'https://fcm.googleapis.com/fcm/send',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Authorization: key=' . $serverKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($curlError) {
            throw new \RuntimeException('CURL error: ' . $curlError);
        }

        if ($httpCode !== 200) {
            Log::warning('FCM returned non-200', [
                'http_code' => $httpCode,
                'response' => substr((string) $response, 0, 300),
            ]);
        }
    }
}
