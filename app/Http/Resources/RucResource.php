<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RucResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ruc' => $this->resource['ruc'] ?? '',
            'razon_social' => $this->resource['razonSocial'] ?? '',
            'nombre_comercial' => $this->resource['nombreComercial'] ?? '',
            'telefonos' => $this->resource['telefonos'] ?? [],
            'estado' => $this->resource['estado'] ?? '',
            'condicion' => $this->resource['condicion'] ?? '',
            'direccion' => $this->resource['direccion'] ?? '',
            'departamento' => $this->resource['departamento'] ?? '',
            'provincia' => $this->resource['provincia'] ?? '',
            'distrito' => $this->resource['distrito'] ?? '',
            'ubigeo' => $this->resource['ubigeo'] ?? '',
            'capital' => $this->resource['capital'] ?? '',
            'direccion_completa' => trim(
                ($this->resource['direccion'] ?? '') . ', ' .
                ($this->resource['distrito'] ?? '') . ', ' .
                ($this->resource['provincia'] ?? '') . ', ' .
                ($this->resource['departamento'] ?? '')
            ),
        ];
    }
}
