<?php

namespace App\Http\Controllers;

use App\Classes\ApiResponseClass;
use App\Http\Resources\SupportTicketResource;
use App\Http\Resources\TicketMessageResource;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\SupportTicketService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class SupportTicketController extends Controller
{
    public function __construct(private SupportTicketService $tickets) {}

    public function index(Request $request)
    {
        try {
            $query = SupportTicket::with(['assignedUser', 'client']);

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

            return ApiResponseClass::sendResponse(
                SupportTicketResource::collection($tickets),
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
            $query = SupportTicket::with(['client'])
                ->where('assigned_user_id', Auth::id());

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            $tickets = $query->orderByDesc('created_at')
                ->paginate($request->input('per_page', 15));

            return ApiResponseClass::sendResponse(
                SupportTicketResource::collection($tickets),
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
                'soldUnit.product',
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
                'status' => 'required|in:abierto,asignado,en_proceso,en_espera_cliente,resuelto,cerrado,cancelado',
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
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al registrar diagnóstico', 500, [$e->getMessage()]);
        }
    }
}
