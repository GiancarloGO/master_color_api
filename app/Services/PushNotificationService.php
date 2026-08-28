<?php

namespace App\Services;

use App\Models\DeviceToken;
use App\Models\PushNotification;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PushNotificationService
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const TOKEN_CACHE_KEY = 'fcm_access_token';

    /**
     * Envía una notificación push a todos los dispositivos de un modelo
     * (Client o User) que tenga la relación `deviceTokens`.
     */
    public function sendToModel(Model $notifiable, string $title, string $body, array $data = []): void
    {
        $this->persist($notifiable, $title, $body, $data);

        if (! method_exists($notifiable, 'deviceTokens')) {
            return;
        }

        $tokens = $notifiable->deviceTokens()->pluck('token')->all();

        $this->sendToTokens($tokens, $title, $body, $data);
    }

    /**
     * Guarda el historial in-app (centro de notificaciones), independiente de
     * si el push por FCM llega o no al dispositivo.
     */
    protected function persist(Model $notifiable, string $title, string $body, array $data): void
    {
        try {
            PushNotification::create([
                'notifiable_type' => $notifiable->getMorphClass(),
                'notifiable_id' => $notifiable->getKey(),
                'type' => $data['type'] ?? null,
                'title' => $title,
                'body' => $body,
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            Log::error('No se pudo guardar la notificación in-app', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Envía a una lista de tokens FCM (HTTP v1). Degrada con gracia si FCM no
     * está configurado o no hay tokens (no lanza excepción).
     *
     * @param  string[]  $tokens
     */
    public function sendToTokens(array $tokens, string $title, string $body, array $data = []): void
    {
        $tokens = array_values(array_filter($tokens));

        if (empty($tokens)) {
            return;
        }

        $projectId = config('services.fcm.project_id');

        if (empty($projectId)) {
            Log::info('Push notification skipped: FCM not configured', [
                'title' => $title,
                'tokens' => count($tokens),
            ]);

            return;
        }

        $accessToken = $this->getAccessToken();

        if ($accessToken === null) {
            return;
        }

        foreach ($tokens as $token) {
            try {
                $this->dispatchToFcm($projectId, $accessToken, $token, $title, $body, $data);
            } catch (\Throwable $e) {
                Log::error('Push notification failed', [
                    'token' => substr($token, 0, 12).'...',
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Obtiene (y cachea) un access token OAuth2 para la API de FCM a partir
     * del service account. `null` si no hay credenciales configuradas o
     * son inválidas — en ese caso se registra el error una sola vez por TTL
     * de caché en lugar de en cada envío.
     */
    protected function getAccessToken(): ?string
    {
        return Cache::remember(self::TOKEN_CACHE_KEY, 3000, function () {
            $credentialsPath = config('services.fcm.credentials');

            if (empty($credentialsPath) || ! is_readable($credentialsPath)) {
                Log::info('Push notification skipped: FCM credentials file not found', [
                    'path' => $credentialsPath,
                ]);

                return null;
            }

            try {
                $credentials = new ServiceAccountCredentials(self::SCOPE, $credentialsPath);
                $token = $credentials->fetchAuthToken();

                return $token['access_token'] ?? null;
            } catch (\Throwable $e) {
                Log::error('FCM: no se pudo obtener el access token', ['error' => $e->getMessage()]);

                return null;
            }
        });
    }

    /**
     * Llamada HTTP real a FCM (HTTP v1). Aislada para poder simularse en tests.
     * En v1, todos los valores de `data` deben ser string.
     */
    protected function dispatchToFcm(string $projectId, string $accessToken, string $token, string $title, string $body, array $data): void
    {
        $response = Http::withToken($accessToken)
            ->timeout(10)
            ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                'message' => [
                    'token' => $token,
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                    ],
                    // FCM v1 exige que `data` sea un mapa (objeto) JSON, no una
                    // lista — `(object)` fuerza `{}` incluso cuando $data está
                    // vacío (un array vacío se serializaría como `[]` y FCM lo
                    // rechaza con "Cannot bind a list to map for field 'data'").
                    'data' => (object) array_map('strval', $data),
                ],
            ]);

        if ($response->failed()) {
            // Un 404/NOT_FOUND (token inválido/desregistrado) es esperable con
            // el tiempo; se registra como warning, no como error crítico.
            Log::warning('FCM returned an error', [
                'http_code' => $response->status(),
                'response' => substr($response->body(), 0, 300),
            ]);

            if ($this->isUnregistered($response)) {
                // FCM confirma que el token ya no existe (reinstalación,
                // desinstalación, rotación del Instance ID...): se borra para
                // no seguir intentando contra él en cada envío futuro.
                DeviceToken::where('token', $token)->delete();
            }
        }
    }

    /**
     * `true` si la respuesta de FCM indica que el token ya no está registrado
     * (UNREGISTERED), a diferencia de otros 404 (proyecto/credenciales mal
     * configurados) que no deben provocar el borrado del token.
     */
    protected function isUnregistered(\Illuminate\Http\Client\Response $response): bool
    {
        if ($response->status() !== 404) {
            return false;
        }

        return $response->json('error.details.0.errorCode') === 'UNREGISTERED';
    }
}
