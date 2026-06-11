<?php

namespace App\Http\Controllers;

use App\Classes\ApiResponseClass;
use App\Http\Resources\SupportTicketResource;
use App\Http\Resources\TicketMessageResource;
use App\Http\Resources\TicketAttachmentResource;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\SupportTicketService;
use App\Services\TicketQuoteService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class SupportTicketController extends Controller
{
    public function __construct(
        private SupportTicketService $tickets,
        private TicketQuoteService $quotes,
    ) {}

    public function index(Request $request)
    {
        try {
            $query = SupportTicket::with(['assignedUser', 'client', 'serviceAddress']);

            foreach (['status', 'priority', 'category', 'assigned_user_id', 'client_id'] as $field) {
                if ($request->filled($field)) {
                    $query->where($field, $request->input($field));
                }
            }

            if ($request->filled('search')) {
                $term = $request->input('search');
                $query->where(function ($q) use ($term) {
                    $q->where('code', 'like', "%{$term}%")
                      ->orWhere('subject', 'like', "%{$term}%")
                      ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$term}%"));
                });
            }

            $tickets = $query->orderByDesc('created_at')
                ->paginate($request->input('per_page', 15));

            return ApiResponseClass::sendPaginatedResponse(
                SupportTicketResource::collection($tickets),
                $tickets,
                'Tickets de soporte',
                200
            );
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al obtener tickets', 500, [$e->getMessage()]);
        }
    }

    public function mine(Request $request)
    {
        try {
            $query = SupportTicket::with(['client', 'serviceAddress'])
                ->where('assigned_user_id', Auth::id());

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            $tickets = $query->orderByDesc('created_at')
                ->paginate($request->input('per_page', 15));

            return ApiResponseClass::sendPaginatedResponse(
                SupportTicketResource::collection($tickets),
                $tickets,
                'Mis tickets asignados',
                200
            );
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al obtener tickets', 500, [$e->getMessage()]);
        }
    }

    public function show(string $id)
    {
        try {
            $ticket = SupportTicket::with([
                'assignedUser',
                'client',
                'serviceAddress',
                'soldUnit.product',
                'parts.stock.product',
                'visits.technician',
                'latestQuote',
                'messages' => fn ($q) => $q->with('attachments')->orderBy('created_at'),
                'attachments',
                'statusHistory' => fn ($q) => $q->orderBy('created_at'),
            ])->find($id);

            if (!$ticket) {
                return ApiResponseClass::errorResponse('Ticket no encontrado', 404);
            }

            return ApiResponseClass::sendResponse(
                new SupportTicketResource($ticket),
                'Detalle de ticket',
                200
            );
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al obtener ticket', 500, [$e->getMessage()]);
        }
    }

    public function assign(Request $request, string $id)
    {
        try {
            $ticket = SupportTicket::find($id);
            if (!$ticket) {
                return ApiResponseClass::errorResponse('Ticket no encontrado', 404);
            }

            $validator = Validator::make($request->all(), [
                'assigned_user_id' => 'required|integer|exists:users,id',
            ]);
            if ($validator->fails()) {
                return ApiResponseClass::errorResponse('Error de validación', 422, $validator->errors());
            }

            $assignee = User::findOrFail($request->assigned_user_id);
            $ticket = $this->tickets->assign($ticket, $assignee, Auth::user());

            return ApiResponseClass::sendResponse(
                new SupportTicketResource($ticket->load('assignedUser')),
                'Ticket asignado',
                200
            );
        } catch (DomainException $e) {
            return ApiResponseClass::errorResponse($e->getMessage(), 409);
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al asignar ticket', 500, [$e->getMessage()]);
        }
    }

    public function status(Request $request, string $id)
    {
        try {
            $ticket = SupportTicket::find($id);
            if (!$ticket) {
                return ApiResponseClass::errorResponse('Ticket no encontrado', 404);
            }

            $validator = Validator::make($request->all(), [
                'status' => 'required|in:abierto,asignado,en_proceso,en_espera_cliente,en_espera_aprobacion,resuelto,cerrado,cancelado',
                'note' => 'nullable|string',
            ]);
            if ($validator->fails()) {
                return ApiResponseClass::errorResponse('Error de validación', 422, $validator->errors());
            }

            $ticket = $this->tickets->changeStatus($ticket, $request->status, Auth::user(), $request->note);

            return ApiResponseClass::sendResponse(
                new SupportTicketResource($ticket),
                'Estado del ticket actualizado',
                200
            );
        } catch (DomainException $e) {
            return ApiResponseClass::errorResponse($e->getMessage(), 409);
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al actualizar estado', 500, [$e->getMessage()]);
        }
    }

    public function messages(Request $request, string $id)
    {
        try {
            $ticket = SupportTicket::find($id);
            if (!$ticket) {
                return ApiResponseClass::errorResponse('Ticket no encontrado', 404);
            }

            $validator = Validator::make($request->all(), [
                'body' => 'required|string',
                'is_internal' => 'nullable|boolean',
            ]);
            if ($validator->fails()) {
                return ApiResponseClass::errorResponse('Error de validación', 422, $validator->errors());
            }

            $message = $this->tickets->addMessage(
                $ticket,
                Auth::user(),
                $request->body,
                $request->boolean('is_internal')
            );

            return ApiResponseClass::sendResponse(
                new TicketMessageResource($message),
                'Mensaje agregado',
                201
            );
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al agregar mensaje', 500, [$e->getMessage()]);
        }
    }

    public function attachments(Request $request, string $id)
    {
        try {
            $ticket = SupportTicket::find($id);
            if (!$ticket) {
                return ApiResponseClass::errorResponse('Ticket no encontrado', 404);
            }

            $validator = Validator::make($request->all(), [
                'files' => 'required|array|min:1|max:5',
                'files.*' => 'file|mimes:jpg,jpeg,png,webp|max:5120',
                'message_id' => 'nullable|integer|exists:ticket_messages,id',
            ]);
            if ($validator->fails()) {
                return ApiResponseClass::errorResponse('Error de validación', 422, $validator->errors());
            }

            $attachments = $this->tickets->addAttachments(
                $ticket,
                $request->file('files'),
                Auth::user(),
                $request->input('message_id')
            );

            return ApiResponseClass::sendResponse(
                TicketAttachmentResource::collection(collect($attachments)),
                'Adjuntos cargados',
                201
            );
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al cargar adjuntos', 500, [$e->getMessage()]);
        }
    }

    public function diagnosis(Request $request, string $id)
    {
        try {
            $ticket = SupportTicket::find($id);
            if (!$ticket) {
                return ApiResponseClass::errorResponse('Ticket no encontrado', 404);
            }

            $validator = Validator::make($request->all(), [
                'diagnosis' => 'required|string',
                'parts_used' => 'nullable|string',
                'resolve' => 'nullable|boolean',
            ]);
            if ($validator->fails()) {
                return ApiResponseClass::errorResponse('Error de validación', 422, $validator->errors());
            }

            $ticket = $this->tickets->registerDiagnosis($ticket, $validator->validated(), Auth::user());

            return ApiResponseClass::sendResponse(
                new SupportTicketResource($ticket),
                'Diagnóstico registrado',
                200
            );
        } catch (DomainException $e) {
            return ApiResponseClass::errorResponse($e->getMessage(), 409);
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al registrar diagnóstico', 500, [$e->getMessage()]);
        }
    }

    /**
     * Programar (o reprogramar) la visita del ticket.
     */
    public function schedule(Request $request, string $id)
    {
        try {
            $ticket = SupportTicket::find($id);
            if (!$ticket) {
                return ApiResponseClass::errorResponse('Ticket no encontrado', 404);
            }

            $validator = Validator::make($request->all(), [
                'scheduled_at' => 'required|date',
                'scheduled_window_minutes' => 'nullable|integer|min:0|max:1440',
                'note' => 'nullable|string',
            ]);
            if ($validator->fails()) {
                return ApiResponseClass::errorResponse('Error de validación', 422, $validator->errors());
            }

            $ticket = $this->tickets->schedule($ticket, $validator->validated(), Auth::user());

            return ApiResponseClass::sendResponse(
                new SupportTicketResource($ticket),
                'Visita programada',
                200
            );
        } catch (DomainException $e) {
            return ApiResponseClass::errorResponse($e->getMessage(), 409);
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al programar visita', 500, [$e->getMessage()]);
        }
    }

    /**
     * Tickets con SLA vencido o por vencer (para escalamiento).
     * filter: breached | due_soon | all (default: all = vencidos + por vencer).
     */
    public function sla(Request $request)
    {
        try {
            $filter = $request->input('filter', 'all');
            $soonLimit = now()->addHours(SupportTicket::SLA_DUE_SOON_HOURS);

            $query = SupportTicket::with(['assignedUser', 'client', 'serviceAddress'])
                ->openForSla();

            $query->where(function ($q) use ($filter, $soonLimit) {
                if ($filter === 'breached') {
                    $q->where('sla_due_at', '<', now());
                } elseif ($filter === 'due_soon') {
                    $q->whereBetween('sla_due_at', [now(), $soonLimit]);
                } else {
                    // Vencidos + por vencer (todo lo que requiere atención).
                    $q->where('sla_due_at', '<=', $soonLimit);
                }
            });

            $tickets = $query->orderBy('sla_due_at')
                ->paginate($request->input('per_page', 15));

            return ApiResponseClass::sendPaginatedResponse(
                SupportTicketResource::collection($tickets),
                $tickets,
                'Tickets por SLA',
                200
            );
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al obtener tickets por SLA', 500, [$e->getMessage()]);
        }
    }

    /**
     * Agenda del técnico autenticado para una fecha (default: hoy).
     */
    public function agenda(Request $request)
    {
        try {
            $date = $request->filled('date')
                ? \Illuminate\Support\Carbon::parse($request->input('date'))
                : \Illuminate\Support\Carbon::today();

            $tickets = SupportTicket::with(['client', 'serviceAddress'])
                ->where('assigned_user_id', Auth::id())
                ->whereNotNull('scheduled_at')
                ->whereDate('scheduled_at', $date->toDateString())
                ->orderBy('scheduled_at')
                ->get();

            return ApiResponseClass::sendResponse(
                SupportTicketResource::collection($tickets),
                'Agenda del ' . $date->toDateString(),
                200
            );
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al obtener la agenda', 500, [$e->getMessage()]);
        }
    }

    /**
     * Crear una cotización/presupuesto para el ticket (staff).
     */
    public function quote(Request $request, string $id)
    {
        try {
            $ticket = SupportTicket::find($id);
            if (!$ticket) {
                return ApiResponseClass::errorResponse('Ticket no encontrado', 404);
            }

            $validator = Validator::make($request->all(), [
                'labor_cost' => 'required|numeric|min:0|max:99999999.99',
                'parts_cost' => 'nullable|numeric|min:0|max:99999999.99',
                'currency' => 'nullable|string|size:3',
                'note' => 'nullable|string',
            ]);
            if ($validator->fails()) {
                return ApiResponseClass::errorResponse('Error de validación', 422, $validator->errors());
            }

            $this->quotes->createQuote($ticket, $validator->validated(), Auth::user());

            return ApiResponseClass::sendResponse(
                new SupportTicketResource($ticket->fresh()->load('latestQuote')),
                'Presupuesto creado',
                201
            );
        } catch (DomainException $e) {
            return ApiResponseClass::errorResponse($e->getMessage(), 409);
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al crear presupuesto', 500, [$e->getMessage()]);
        }
    }
}
