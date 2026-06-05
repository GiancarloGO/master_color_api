<?php

namespace App\Http\Resources;

use App\Services\FileUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SoldUnitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'product_id' => $this->product_id,
            'product_name' => $this->whenLoaded('product', fn () => $this->product->name),
            'order_id' => $this->order_id,
            'serial_number' => $this->serial_number,
            'purchase_date' => $this->purchase_date?->toDateString(),
            'warranty_months' => $this->warranty_months,
            'warranty_expires_at' => $this->warranty_expires_at?->toDateString(),
            'warranty_active' => $this->warranty_active,
            'warranty_status' => $this->warranty_status,
            'registration_source' => $this->registration_source,
            'proof_file_url' => $this->proof_file_path
                ? app(FileUploadService::class)->getImageUrl($this->proof_file_path)
                : null,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'tickets' => $this->whenLoaded('tickets', fn () => $this->tickets),
        ];
    }

    /**
     * Bloque de garantía con el mismo shape que el schema Warranty del OpenAPI.
     */
    public function warrantyArray(): array
    {
        return [
            'sold_unit_id' => $this->id,
            'purchase_date' => $this->purchase_date?->toDateString(),
            'warranty_months' => $this->warranty_months,
            'expires_at' => $this->warranty_expires_at?->toDateString(),
            'active' => $this->warranty_active,
            'days_remaining' => $this->warranty_days_remaining,
            'status' => $this->warranty_status,
        ];
    }
}
