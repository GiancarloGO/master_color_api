<?php

namespace App\Http\Controllers;

use App\Classes\ApiResponseClass;
use App\Http\Resources\TechnicianResource;
use App\Models\User;
use Illuminate\Http\Request;

class SupportTechnicianController extends Controller
{
    /**
     * Listar técnicos asignables a un ticket (rol "Tecnico", activos).
     */
    public function index(Request $request)
    {
        try {
            $query = User::whereHas('role', fn ($q) => $q->where('name', 'Tecnico'))
                ->where('is_active', true);

            if ($request->filled('search')) {
                $term = $request->input('search');
                $query->where(function ($q) use ($term) {
                    $q->where('name', 'like', "%{$term}%")
                      ->orWhere('email', 'like', "%{$term}%");
                });
            }

            $technicians = $query->orderBy('name')
                ->paginate($request->input('per_page', 15));

            return ApiResponseClass::sendPaginatedResponse(
                TechnicianResource::collection($technicians),
                $technicians,
                'Técnicos asignables',
                200
            );
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al obtener técnicos', 500, [$e->getMessage()]);
        }
    }
}
