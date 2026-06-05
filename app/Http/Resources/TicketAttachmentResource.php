<?php

namespace App\Http\Resources;

use App\Services\FileUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketAttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_id' => $this->ticket_id,
            'message_id' => $this->ticket_message_id,
            'url' => app(FileUploadService::class)->getImageUrl($this->file_path),
            'file_type' => $this->file_type,
            'original_name' => $this->original_name,
            'created_at' => $this->created_at,
        ];
    }
}
