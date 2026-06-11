<?php

namespace App\Http\Resources;

use App\Services\FileUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketVisitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $files = app(FileUploadService::class);

        return [
            'id' => $this->id,
            'ticket_id' => $this->ticket_id,
            'technician_id' => $this->technician_id,
            'technician_name' => $this->whenLoaded('technician', fn () => $this->technician?->name),
            'checkin_at' => $this->checkin_at,
            'checkin_latitude' => $this->checkin_latitude !== null ? (float) $this->checkin_latitude : null,
            'checkin_longitude' => $this->checkin_longitude !== null ? (float) $this->checkin_longitude : null,
            'checkout_at' => $this->checkout_at,
            'checkout_latitude' => $this->checkout_latitude !== null ? (float) $this->checkout_latitude : null,
            'checkout_longitude' => $this->checkout_longitude !== null ? (float) $this->checkout_longitude : null,
            'duration_minutes' => $this->durationMinutes(),
            'work_done' => $this->work_done,
            'client_signed_name' => $this->client_signed_name,
            'signature_url' => $files->getImageUrl($this->signature_path),
            'report_pdf_url' => $files->getImageUrl($this->report_pdf_path),
            'reported_at' => $this->reported_at,
            'created_at' => $this->created_at,
        ];
    }
}
