<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientProductResource extends JsonResource
{
    /**
     * Vista ligera del producto para la app (selección al registrar una unidad).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sku' => $this->sku,
            'warranty_months' => $this->default_warranty_months,
        ];
    }
}
