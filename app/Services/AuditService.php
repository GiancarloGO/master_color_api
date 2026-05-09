<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class AuditService
{
    public function logStaffAction(
        User $actor,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $metadata = null
    ): void {
        $this->write('staff', $actor->id, $actor->name, $action, $entityType, $entityId, $oldValues, $newValues, $metadata);
    }

    public function logClientAction(
        Client $actor,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $metadata = null
    ): void {
        $this->write('client', $actor->id, $actor->name, $action, $entityType, $entityId, $oldValues, $newValues, $metadata);
    }

    public function logSystemAction(
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $metadata = null
    ): void {
        $this->write('system', null, 'system', $action, $entityType, $entityId, null, null, $metadata);
    }

    private function write(
        string $actorType,
        ?int $actorId,
        ?string $actorName,
        string $action,
        ?string $entityType,
        ?int $entityId,
        ?array $oldValues,
        ?array $newValues,
        ?array $metadata
    ): void {
        try {
            AuditLog::create([
                'actor_type'  => $actorType,
                'actor_id'    => $actorId,
                'actor_name'  => $actorName,
                'action'      => $action,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'old_values'  => $oldValues,
                'new_values'  => $newValues,
                'ip_address'  => request()->ip(),
                'user_agent'  => substr(request()->userAgent() ?? '', 0, 512),
                'metadata'    => $metadata,
            ]);
        } catch (\Throwable $e) {
            Log::error('AuditService failed to write log', [
                'action' => $action,
                'error'  => $e->getMessage(),
            ]);
        }
    }
}
