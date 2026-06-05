<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupportTicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'client_id' => $this->client_id,
            'sold_unit_id' => $this->sold_unit_id,
            'product_id' => $this->product_id,
            'category' => $this->category,
            'priority' => $this->priority,
            'subject' => $this->subject,
            'description' => $this->description,
            'status' => $this->status,
            'channel' => $this->channel,
            'assigned_user_id' => $this->assigned_user_id,
            'assigned_user_name' => $this->whenLoaded('assignedUser', fn () => $this->assignedUser?->name),
            'is_warranty_covered' => $this->is_warranty_covered,
            'sla_due_at' => $this->sla_due_at,
            'first_response_at' => $this->first_response_at,
            'resolved_at' => $this->resolved_at,
            'closed_at' => $this->closed_at,
            'rating' => $this->rating,
            'rating_comment' => $this->rating_comment,
            'diagnosis' => $this->diagnosis,
            'parts_used' => $this->parts_used,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'sold_unit' => new SoldUnitResource($this->whenLoaded('soldUnit')),
            'client' => $this->whenLoaded('client', fn () => [
                'id' => $this->client->id,
                'name' => $this->client->name,
                'email' => $this->client->email,
                'phone' => $this->client->phone,
            ]),
            'messages' => TicketMessageResource::collection($this->whenLoaded('messages')),
            'attachments' => TicketAttachmentResource::collection($this->whenLoaded('attachments')),
            'status_history' => TicketStatusHistoryResource::collection($this->whenLoaded('statusHistory')),
        ];
    }
}
