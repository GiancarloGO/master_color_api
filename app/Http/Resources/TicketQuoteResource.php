<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketQuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_id' => $this->ticket_id,
            'labor_cost' => (float) $this->labor_cost,
            'parts_cost' => (float) $this->parts_cost,
            'total' => (float) $this->total,
            'currency' => $this->currency,
            'status' => $this->status,
            'note' => $this->note,
            'created_by_user_id' => $this->created_by_user_id,
            'decided_at' => $this->decided_at,
            'created_at' => $this->created_at,
        ];
    }
}
