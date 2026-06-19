<?php

namespace App\Http\Controllers;

use App\Classes\ApiResponseClass;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = AuditLog::query()->orderBy('created_at', 'desc');

            if ($request->filled('action')) {
                $query->where('action', 'like', '%' . $request->action . '%');
            }

            if ($request->filled('actor_type')) {
                $query->where('actor_type', $request->actor_type);
            }

            if ($request->filled('actor_id')) {
                $query->where('actor_id', $request->actor_id);
            }

            if ($request->filled('entity_type')) {
                $query->where('entity_type', $request->entity_type);
            }

            if ($request->filled('entity_id')) {
                $query->where('entity_id', $request->entity_id);
            }

            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            $perPage = $request->integer('per_page', 50);
            $logs = $query->paginate(min($perPage, 200));

            return ApiResponseClass::sendPaginatedResponse(
                AuditLogResource::collection($logs),
                $logs,
                'Registros de auditoría',
                200
            );
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al obtener registros de auditoría', 500, [$e->getMessage()]);
        }
    }

    public function show(string $id)
    {
        try {
            $log = AuditLog::findOrFail($id);

            return ApiResponseClass::sendResponse(
                new AuditLogResource($log),
                'Detalle del registro de auditoría'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Registro no encontrado', 404, [$e->getMessage()]);
        }
    }
}
