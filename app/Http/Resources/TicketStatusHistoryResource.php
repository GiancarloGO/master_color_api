<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketStatusHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'changed_by_type' => $this->changed_by_type,
            'changed_by_name' => $this->changed_by_name,
            'note' => $this->note,
            'created_at' => $this->created_at,
        ];
    }
}
