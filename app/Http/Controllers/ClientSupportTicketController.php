<?php

namespace App\Http\Controllers;

use App\Classes\ApiResponseClass;
use App\Http\Resources\SupportTicketResource;
use App\Http\Resources\TicketMessageResource;
use App\Http\Resources\TicketAttachmentResource;
use App\Models\SoldUnit;
use App\Services\SupportTicketService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ClientSupportTicketController extends Controller
{
    public function __construct(private SupportTicketService $tickets) {}

    public function index(Request $request)
    {
        try {
            $client = Auth::guard('client')->user();
            if (!$client) {
                return ApiResponseClass::errorResponse('No autenticado', 401);
            }

            $query = $client->supportTickets()->with('assignedUser');

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            $tickets = $query->orderByDesc('created_at')
                ->paginate($request->input('per_page', 15));

            return ApiResponseClass::sendPaginatedResponse(
                SupportTicketResource::collection($tickets),
                $tickets,
                'Mis tickets de soporte',
                200
            );
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al obtener tickets', 500, [$e->getMessage()]);
        }
    }

    public function store(Request $request)
    {
        try {
            $client = Auth::guard('client')->user();
            if (!$client) {
                return ApiResponseClass::errorResponse('No autenticado', 401);
            }

            $validator = Validator::make($request->all(), [
                'sold_unit_id' => 'nullable|integer|exists:sold_units,id',
                'category' => 'required|in:garantia,instalacion,falla,consulta,otro',
                'priority' => 'nullable|in:baja,media,alta,urgente',
                'subject' => 'required|string|max:150',
                'description' => 'required|string',
            ]);

            if ($validator->fails()) {
                return ApiResponseClass::errorResponse('Error de validación', 422, $validator->errors());
            }

            $unit = null;
            if ($request->filled('sold_unit_id')) {
                $unit = SoldUnit::where('id', $request->sold_unit_id)
                    ->where('client_id', $client->id)
                    ->first();

                if (!$unit) {
                    return ApiResponseClass::errorResponse('La unidad no pertenece al cliente', 422);
                }
            }

            $ticket = $this->tickets->createForClient($client, $validator->validated(), $unit);

            return ApiResponseClass::sendResponse(
                new SupportTicketResource($ticket),
                'Ticket creado exitosamente',
                201
            );
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al crear ticket', 500, [$e->getMessage()]);
        }
    }

    public function show(Request $request, string $id)
    {
        try {
            $ticket = $this->findClientTicket($id);
            if (!$ticket) {
                return ApiResponseClass::errorResponse('Ticket no encontrado', 404);
            }

            $ticket->load([
                'assignedUser',
                'soldUnit.product',
                // Solo mensajes públicos para el cliente.
                'messages' => fn ($q) => $q->where('is_internal', false)->with('attachments')->orderBy('created_at'),
                'attachments',
                'statusHistory' => fn ($q) => $q->orderBy('created_at'),
            ]);

            return ApiResponseClass::sendResponse(
                new SupportTicketResource($ticket),
                'Detalle de ticket',
                200
            );
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al obtener ticket', 500, [$e->getMessage()]);
        }
    }

    public function messages(Request $request, string $id)
    {
        try {
            $ticket = $this->findClientTicket($id);
            if (!$ticket) {
                return ApiResponseClass::errorResponse('Ticket no encontrado', 404);
            }

            $validator = Validator::make($request->all(), [
                'body' => 'required|string',
            ]);
            if ($validator->fails()) {
                return ApiResponseClass::errorResponse('Error de validación', 422, $validator->errors());
            }

            $message = $this->tickets->addMessage($ticket, Auth::guard('client')->user(), $request->body, false);

            return ApiResponseClass::sendResponse(
                new TicketMessageResource($message),
                'Mensaje enviado',
                201
            );
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al enviar mensaje', 500, [$e->getMessage()]);
        }
    }

    public function attachments(Request $request, string $id)
    {
        try {
            $ticket = $this->findClientTicket($id);
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
                Auth::guard('client')->user(),
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

    public function rate(Request $request, string $id)
    {
        try {
            $ticket = $this->findClientTicket($id);
            if (!$ticket) {
                return ApiResponseClass::errorResponse('Ticket no encontrado', 404);
            }

            $validator = Validator::make($request->all(), [
                'rating' => 'required|integer|min:1|max:5',
                'comment' => 'nullable|string',
            ]);
            if ($validator->fails()) {
                return ApiResponseClass::errorResponse('Error de validación', 422, $validator->errors());
            }

            $ticket = $this->tickets->rate($ticket, (int) $request->rating, $request->comment, Auth::guard('client')->user());

            return ApiResponseClass::sendResponse(
                new SupportTicketResource($ticket),
                'Gracias por tu calificación',
                200
            );
        } catch (DomainException $e) {
            return ApiResponseClass::errorResponse($e->getMessage(), 409);
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al calificar ticket', 500, [$e->getMessage()]);
        }
    }

    public function reopen(Request $request, string $id)
    {
        try {
            $ticket = $this->findClientTicket($id);
            if (!$ticket) {
                return ApiResponseClass::errorResponse('Ticket no encontrado', 404);
            }

            $validator = Validator::make($request->all(), [
                'reason' => 'required|string',
            ]);
            if ($validator->fails()) {
                return ApiResponseClass::errorResponse('Error de validación', 422, $validator->errors());
            }

            $ticket = $this->tickets->reopen($ticket, $request->reason, Auth::guard('client')->user());

            return ApiResponseClass::sendResponse(
                new SupportTicketResource($ticket),
                'Ticket reabierto',
                200
            );
        } catch (DomainException $e) {
            return ApiResponseClass::errorResponse($e->getMessage(), 409);
        } catch (\Exception $e) {
            return ApiResponseClass::errorResponse('Error al reabrir ticket', 500, [$e->getMessage()]);
        }
    }

    /**
     * Busca un ticket que pertenezca al cliente autenticado.
     */
    private function findClientTicket(string $id): ?\App\Models\SupportTicket
    {
        $client = Auth::guard('client')->user();
        if (!$client) {
            return null;
        }

        return $client->supportTickets()->find($id);
    }
}
