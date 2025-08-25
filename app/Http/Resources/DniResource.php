<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DniResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'dni' => $this->resource['dni'] ?? '',
            'nombres' => $this->resource['nombres'] ?? '',
            'apellido_paterno' => $this->resource['apellidoPaterno'] ?? '',
            'apellido_materno' => $this->resource['apellidoMaterno'] ?? '',
            'codigo_verifica' => $this->resource['codVerifica'] ?? '',
            'nombre_completo' => trim(
                ($this->resource['nombres'] ?? '') . ' ' . 
                ($this->resource['apellidoPaterno'] ?? '') . ' ' . 
                ($this->resource['apellidoMaterno'] ?? '')
            ),
        ];
    }
}
