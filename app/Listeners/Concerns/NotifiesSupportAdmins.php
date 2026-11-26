<?php

namespace App\Listeners\Concerns;

use App\Models\User;

trait NotifiesSupportAdmins
{
    /**
     * Envía una notificación push/in-app a todos los administradores activos,
     * excluyendo opcionalmente IDs ya notificados (p. ej. si el técnico asignado
     * también tiene rol Admin).
     *
     * @param  int[]  $excludeUserIds
     * @param  array<string, mixed>  $payload
     */
    protected function notifyActiveAdmins(string $title, string $body, array $payload, array $excludeUserIds = []): void
    {
        $admins = User::where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('name', 'Admin'))
            ->when($excludeUserIds !== [], fn ($q) => $q->whereNotIn('id', $excludeUserIds))
            ->get();

        foreach ($admins as $admin) {
            $this->push->sendToModel($admin, $title, $body, $payload);
        }
    }
}
