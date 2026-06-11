<?php

namespace App\Http\Controllers;

use App\Classes\ApiResponseClass;
use App\Http\Resources\TechnicianResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class SupportTechnicianController extends Controller
{
    /**
     * Categorías de ticket que un técnico puede declarar como especialidad.
     */
    private const SPECIALTIES = ['garantia', 'instalacion', 'falla', 'consulta', 'otro'];

    /**
     * Listar técnicos asignables a un ticket (rol "Tecnico", activos).
     * Filtros para asignación inteligente: especialidad, zona y disponibilidad.
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

            // Solo disponibles (para sugerir técnicos que aceptan nuevas visitas).
            if ($request->boolean('available_only')) {
                $query->where('is_available', true);
            }

            // Técnicos que atienden esta categoría/especialidad.
            if ($request->filled('specialty')) {
                $query->whereJsonContains('specialties', $request->input('specialty'));
            }

            // Técnicos que cubren esta zona/distrito.
            if ($request->filled('zone')) {
                $query->whereJsonContains('coverage_zones', $request->input('zone'));
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

    /**
     * Actualizar el perfil del técnico autenticado (especialidades, zonas, disponibilidad).
     */
    public function updateProfile(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return ApiResponseClass::errorResponse('No autenticado', 401);
            }

            $validator = Validator::make($request->all(), [
                'specialties' => 'sometimes|array',
                'specialties.*' => 'string|in:' . implode(',', self::SPECIALTIES),
                'coverage_zones' => 'sometimes|array',
                'coverage_zones.*' => 'string|max:100',
                'is_available' => 'sometimes|boolean',
            ]);
            if ($validator->fails()) {
                return ApiResponseClass::errorResponse('Error de validación', 422, $validator->errors());
            }

            $user->fill($validator->validated())->save();

            return ApiResponseClass::sendResponse(
                new TechnicianResource($user->fresh()),
                'Perfil actualizado',
                200
            );
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al actualizar el perfil', 500, [$e->getMessage()]);
        }
    }
}
