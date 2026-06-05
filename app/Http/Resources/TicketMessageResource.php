<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_id' => $this->ticket_id,
            'author_type' => $this->author_type,
            'author_id' => $this->author_id,
            'author_name' => $this->author_name,
            'body' => $this->body,
            'is_internal' => $this->is_internal,
            'read_at' => $this->read_at,
            'created_at' => $this->created_at,
            'attachments' => TicketAttachmentResource::collection($this->whenLoaded('attachments')),
        ];
    }
}
