<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketPartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $product = $this->whenLoaded('stock', fn () => $this->stock->product ?? null);

        return [
            'id' => $this->id,
            'stock_id' => $this->stock_id,
            'product_name' => $product?->name,
            'sku' => $product?->sku,
            'quantity' => $this->quantity,
            'unit_cost' => $this->unit_cost !== null ? (float) $this->unit_cost : null,
            'created_at' => $this->created_at,
        ];
    }
}
