<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TechnicianResource extends JsonResource
{
    /**
     * Técnico asignable a un ticket de soporte.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'active' => (bool) $this->is_active,
            'is_available' => (bool) $this->is_available,
            'specialties' => $this->specialties ?? [],
            'coverage_zones' => $this->coverage_zones ?? [],
        ];
    }
}
