<?php

namespace App\Http\Controllers;

use App\Classes\ApiResponseClass;
use App\Http\Resources\SoldUnitResource;
use App\Models\SoldUnit;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class SupportUnitController extends Controller
{
    public function __construct(private AuditService $audit) {}

    /**
     * Buscar unidades vendidas (vista staff).
     */
    public function index(Request $request)
    {
        try {
            $query = SoldUnit::with(['product', 'client']);

            if ($request->filled('serial_number')) {
                $query->where('serial_number', 'like', '%' . $request->serial_number . '%');
            }

            if ($request->filled('client_id')) {
                $query->where('client_id', $request->client_id);
            }

            if ($request->filled('product_id')) {
                $query->where('product_id', $request->product_id);
            }

            $units = $query->orderByDesc('created_at')
                ->paginate($request->input('per_page', 15));

            return ApiResponseClass::sendPaginatedResponse(
                SoldUnitResource::collection($units),
                $units,
                'Unidades vendidas',
                200
            );
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al obtener unidades', 500, [$e->getMessage()]);
        }
    }

    /**
     * Detalle de una unidad (vista staff).
     */
    public function show(string $id)
    {
        try {
            $unit = SoldUnit::with(['product', 'client', 'order'])->find($id);

            if (!$unit) {
                return ApiResponseClass::errorResponse('Unidad no encontrada', 404);
            }

            return ApiResponseClass::sendResponse(
                new SoldUnitResource($unit),
                'Detalle de unidad',
                200
            );
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al obtener unidad', 500, [$e->getMessage()]);
        }
    }

    /**
     * Historial de servicio del equipo: línea de tiempo cronológica de
     * aperturas de ticket, visitas (check-in/out) y diagnósticos/resoluciones.
     */
    public function history(string $id)
    {
        try {
            $unit = SoldUnit::with([
                'product',
                'tickets' => fn ($q) => $q->orderByDesc('created_at'),
                'tickets.visits.technician',
                'tickets.assignedUser',
            ])->find($id);

            if (!$unit) {
                return ApiResponseClass::errorResponse('Unidad no encontrada', 404);
            }

            $timeline = [];

            foreach ($unit->tickets as $ticket) {
                $timeline[] = [
                    'type' => 'ticket_opened',
                    'at' => $ticket->created_at,
                    'ticket_id' => $ticket->id,
                    'ticket_code' => $ticket->code,
                    'category' => $ticket->category,
                    'subject' => $ticket->subject,
                ];

                foreach ($ticket->visits as $visit) {
                    $timeline[] = [
                        'type' => 'visit',
                        'at' => $visit->checkin_at ?? $visit->reported_at ?? $visit->created_at,
                        'ticket_id' => $ticket->id,
                        'ticket_code' => $ticket->code,
                        'technician' => $visit->technician?->name,
                        'checkin_at' => $visit->checkin_at,
                        'checkout_at' => $visit->checkout_at,
                        'duration_minutes' => $visit->durationMinutes(),
                        'work_done' => $visit->work_done,
                        'reported_at' => $visit->reported_at,
                    ];
                }

                if ($ticket->resolved_at) {
                    $timeline[] = [
                        'type' => 'resolved',
                        'at' => $ticket->resolved_at,
                        'ticket_id' => $ticket->id,
                        'ticket_code' => $ticket->code,
                        'technician' => $ticket->assignedUser?->name,
                        'diagnosis' => $ticket->diagnosis,
                    ];
                }
            }

            // Más reciente primero.
            usort($timeline, fn ($a, $b) => ($b['at']?->timestamp ?? 0) <=> ($a['at']?->timestamp ?? 0));

            return ApiResponseClass::sendResponse([
                'unit' => new SoldUnitResource($unit),
                'tickets_count' => $unit->tickets->count(),
                'timeline' => $timeline,
            ], 'Historial de servicio de la unidad', 200);
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al obtener el historial', 500, [$e->getMessage()]);
        }
    }

    /**
     * Actualizar una unidad (asignar nº de serie y/o estado).
     */
    public function update(Request $request, string $id)
    {
        try {
            $unit = SoldUnit::find($id);
            if (!$unit) {
                return ApiResponseClass::errorResponse('Unidad no encontrada', 404);
            }

            $validator = Validator::make($request->all(), [
                'serial_number' => 'nullable|string|max:191',
                'status' => 'nullable|in:activa,en_servicio,baja',
            ]);
            if ($validator->fails()) {
                return ApiResponseClass::errorResponse('Error de validación', 422, $validator->errors());
            }

            // Evitar dos unidades del mismo producto con idéntico nº de serie.
            if ($request->filled('serial_number')) {
                $duplicate = SoldUnit::where('product_id', $unit->product_id)
                    ->where('serial_number', $request->serial_number)
                    ->where('id', '!=', $unit->id)
                    ->exists();

                if ($duplicate) {
                    return ApiResponseClass::errorResponse('Ya existe una unidad de este producto con ese número de serie', 422);
                }
            }

            $old = ['serial_number' => $unit->serial_number, 'status' => $unit->status];
            $unit->fill($request->only(['serial_number', 'status']));
            $unit->save();

            $actor = Auth::user();
            if ($actor) {
                $this->audit->logStaffAction($actor, 'sold_unit.updated', 'SoldUnit', $unit->id, $old, [
                    'serial_number' => $unit->serial_number,
                    'status' => $unit->status,
                ]);
            }

            return ApiResponseClass::sendResponse(
                new SoldUnitResource($unit->load('product')),
                'Unidad actualizada',
                200
            );
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al actualizar unidad', 500, [$e->getMessage()]);
        }
    }
}
