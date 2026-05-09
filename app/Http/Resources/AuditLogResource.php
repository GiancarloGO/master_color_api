<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'actor_type'  => $this->actor_type,
            'actor_id'    => $this->actor_id,
            'actor_name'  => $this->actor_name,
            'action'      => $this->action,
            'entity_type' => $this->entity_type,
            'entity_id'   => $this->entity_id,
            'old_values'  => $this->old_values,
            'new_values'  => $this->new_values,
            'ip_address'  => $this->ip_address,
            'user_agent'  => $this->user_agent,
            'metadata'    => $this->metadata,
            'created_at'  => $this->created_at?->toISOString(),
        ];
    }
}
