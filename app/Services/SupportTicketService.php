<?php

namespace App\Services;

use App\Models\Client;
use App\Models\SoldUnit;
use App\Models\SupportTicket;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use App\Models\TicketStatusHistory;
use App\Models\User;
use App\Events\TicketAssigned;
use App\Events\TicketMessageCreated;
use App\Events\TicketStatusChanged;
use App\Services\AuditService;
use App\Services\FileUploadService;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class SupportTicketService
{
    /**
     * Transiciones de estado permitidas del ticket.
     */
    private const TRANSITIONS = [
        'abierto' => ['asignado', 'en_proceso', 'cancelado'],
        'asignado' => ['en_proceso', 'cancelado'],
        'en_proceso' => ['en_espera_cliente', 'en_espera_aprobacion', 'resuelto', 'cancelado'],
        'en_espera_cliente' => ['en_proceso', 'cancelado'],
        'en_espera_aprobacion' => ['en_proceso', 'cancelado'],
        'resuelto' => ['cerrado', 'en_proceso'],
        'cerrado' => ['en_proceso'],
        'cancelado' => [],
    ];

    /**
     * Horas de SLA según prioridad.
     */
    private const SLA_HOURS = [
        'urgente' => 4,
        'alta' => 8,
        'media' => 24,
        'baja' => 72,
    ];

    public function __construct(
        private AuditService $audit,
        private FileUploadService $files,
    ) {}

    public static function isValidTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * Crear un ticket en nombre de un cliente.
     */
    public function createForClient(Client $client, array $data, ?SoldUnit $unit = null): SupportTicket
    {
        return DB::transaction(function () use ($client, $data, $unit) {
            $priority = $data['priority'] ?? 'media';

            $ticket = SupportTicket::create([
                'code' => 'TMP',
                'client_id' => $client->id,
                'sold_unit_id' => $unit?->id,
                'product_id' => $unit?->product_id,
                'category' => $data['category'],
                'priority' => $priority,
                'subject' => $data['subject'],
                'description' => $data['description'],
                'status' => 'abierto',
                'channel' => $data['channel'] ?? 'app',
                'service_type' => $data['service_type'] ?? 'remoto',
                'service_address_id' => $data['service_address_id'] ?? null,
                'is_warranty_covered' => $unit ? (bool) $unit->warranty_active : null,
                'sla_due_at' => now()->addHours(self::SLA_HOURS[$priority] ?? 24),
            ]);

            // Código legible una vez conocido el id.
            $ticket->update(['code' => 'SOP-' . now()->format('Y') . '-' . str_pad((string) $ticket->id, 4, '0', STR_PAD_LEFT)]);

            $this->recordStatus($ticket, null, 'abierto', $client, 'Ticket creado');

            $this->audit->logClientAction($client, 'support_ticket.created', 'SupportTicket', $ticket->id, null, [
                'code' => $ticket->code,
                'category' => $ticket->category,
                'priority' => $ticket->priority,
            ]);

            return $ticket->fresh();
        });
    }

    /**
     * Agregar un mensaje al ticket (cliente o staff).
     */
    public function addMessage(SupportTicket $ticket, Model $actor, string $body, bool $isInternal = false): TicketMessage
    {
        $message = DB::transaction(function () use ($ticket, $actor, $body, $isInternal) {
            $meta = $this->actorMeta($actor);

            // Las notas internas solo las puede crear el staff.
            $isInternal = $isInternal && $meta['type'] === 'user';

            $message = TicketMessage::create([
                'ticket_id' => $ticket->id,
                'author_type' => $meta['type'],
                'author_id' => $meta['id'],
                'author_name' => $meta['name'],
                'body' => $body,
                'is_internal' => $isInternal,
            ]);

            // Primera respuesta pública del staff marca el first_response_at (métrica SLA).
            if ($meta['type'] === 'user' && !$isInternal && !$ticket->first_response_at) {
                $ticket->update(['first_response_at' => now()]);
            }

            return $message;
        });

        TicketMessageCreated::dispatch($message->id);

        return $message;
    }

    /**
     * Adjuntar archivos (imágenes) al ticket / a un mensaje.
     *
     * @param  UploadedFile[]  $files
     * @return TicketAttachment[]
     */
    public function addAttachments(SupportTicket $ticket, array $files, Model $uploader, ?int $messageId = null): array
    {
        return DB::transaction(function () use ($ticket, $files, $uploader, $messageId) {
            $meta = $this->actorMeta($uploader);
            $created = [];

            foreach ($files as $file) {
                $path = $this->files->uploadImage($file, 'tickets/' . $ticket->id, 'ticket');

                $created[] = TicketAttachment::create([
                    'ticket_id' => $ticket->id,
                    'ticket_message_id' => $messageId,
                    'file_path' => $path,
                    'file_type' => $file->getClientMimeType(),
                    'original_name' => $file->getClientOriginalName(),
                    'uploaded_by_type' => $meta['type'],
                    'uploaded_by_id' => $meta['id'],
                ]);
            }

            return $created;
        });
    }

    /**
     * Asignar el ticket a un técnico.
     */
    public function assign(SupportTicket $ticket, User $assignee, User $actor): SupportTicket
    {
        // No se puede operar sobre tickets en estado terminal.
        $this->assertNotTerminal($ticket, 'asignar');

        // Solo se asignan tickets a técnicos activos.
        $assignee->loadMissing('role');
        if ($assignee->role?->name !== 'Tecnico' || !$assignee->is_active) {
            throw new DomainException('El usuario asignado debe ser un técnico activo');
        }

        $statusChanged = false;

        $fresh = DB::transaction(function () use ($ticket, $assignee, $actor, &$statusChanged) {
            $ticket->update(['assigned_user_id' => $assignee->id]);

            if ($ticket->status === 'abierto') {
                $this->applyStatus($ticket, 'asignado', $actor, "Asignado a {$assignee->name}");
                $statusChanged = true;
            }

            $this->audit->logStaffAction($actor, 'support_ticket.assigned', 'SupportTicket', $ticket->id, null, [
                'assigned_user_id' => $assignee->id,
            ]);

            return $ticket->fresh();
        });

        TicketAssigned::dispatch($ticket->id, $assignee->id);
        if ($statusChanged) {
            TicketStatusChanged::dispatch($ticket->id, 'abierto', 'asignado', 'user');
        }

        return $fresh;
    }

    /**
     * Cambiar el estado del ticket validando la máquina de estados.
     *
     * @throws DomainException si la transición no es válida.
     */
    public function changeStatus(SupportTicket $ticket, string $newStatus, Model $actor, ?string $note = null): SupportTicket
    {
        if (!self::isValidTransition($ticket->status, $newStatus)) {
            throw new DomainException("No se puede cambiar el estado de '{$ticket->status}' a '{$newStatus}'");
        }

        $from = $ticket->status;

        $fresh = DB::transaction(function () use ($ticket, $newStatus, $actor, $note) {
            $this->applyStatus($ticket, $newStatus, $actor, $note);

            if ($actor instanceof User) {
                $this->audit->logStaffAction($actor, 'support_ticket.status_changed', 'SupportTicket', $ticket->id, null, [
                    'status' => $newStatus,
                ]);
            }

            return $ticket->fresh();
        });

        if ($from !== $newStatus) {
            TicketStatusChanged::dispatch($ticket->id, $from, $newStatus, $this->actorMeta($actor)['type']);
        }

        return $fresh;
    }

    /**
     * Programar (o reprogramar) la visita del ticket.
     */
    public function schedule(SupportTicket $ticket, array $data, User $actor): SupportTicket
    {
        $this->assertNotTerminal($ticket, 'programar');

        $ticket->update([
            'scheduled_at' => $data['scheduled_at'],
            'scheduled_window_minutes' => $data['scheduled_window_minutes'] ?? null,
        ]);

        $this->audit->logStaffAction($actor, 'support_ticket.scheduled', 'SupportTicket', $ticket->id, null, [
            'scheduled_at' => $ticket->scheduled_at?->toIso8601String(),
            'window_minutes' => $ticket->scheduled_window_minutes,
            'note' => $data['note'] ?? null,
        ]);

        return $ticket->fresh();
    }

    /**
     * Registrar diagnóstico técnico y, opcionalmente, resolver el ticket.
     */
    public function registerDiagnosis(SupportTicket $ticket, array $data, User $actor): SupportTicket
    {
        // No se diagnostica un ticket en estado terminal (cerrado/cancelado).
        $this->assertNotTerminal($ticket, 'diagnosticar');

        $resolved = false;
        $from = $ticket->status;

        $fresh = DB::transaction(function () use ($ticket, $data, $actor, &$resolved) {
            $ticket->update([
                'diagnosis' => $data['diagnosis'],
                'parts_used' => $data['parts_used'] ?? $ticket->parts_used,
            ]);

            if (!empty($data['resolve']) && self::isValidTransition($ticket->status, 'resuelto')) {
                $this->applyStatus($ticket, 'resuelto', $actor, 'Resuelto tras diagnóstico');
                $resolved = true;
            }

            $this->audit->logStaffAction($actor, 'support_ticket.diagnosed', 'SupportTicket', $ticket->id, null, [
                'resolved' => (bool) ($data['resolve'] ?? false),
            ]);

            return $ticket->fresh();
        });

        if ($resolved) {
            TicketStatusChanged::dispatch($ticket->id, $from, 'resuelto', 'user');
        }

        return $fresh;
    }

    /**
     * Calificación del cliente para un ticket resuelto/cerrado.
     *
     * @throws DomainException si el ticket no está resuelto/cerrado.
     */
    public function rate(SupportTicket $ticket, int $rating, ?string $comment, Client $actor): SupportTicket
    {
        if (!in_array($ticket->status, ['resuelto', 'cerrado'], true)) {
            throw new DomainException('Solo se puede calificar un ticket resuelto o cerrado');
        }

        $ticket->update(['rating' => $rating, 'rating_comment' => $comment]);

        $this->audit->logClientAction($actor, 'support_ticket.rated', 'SupportTicket', $ticket->id, null, [
            'rating' => $rating,
        ]);

        return $ticket->fresh();
    }

    /**
     * Reabrir un ticket resuelto/cerrado.
     *
     * @throws DomainException si el ticket no está resuelto/cerrado.
     */
    public function reopen(SupportTicket $ticket, string $reason, Client $actor): SupportTicket
    {
        if (!in_array($ticket->status, ['resuelto', 'cerrado'], true)) {
            throw new DomainException('Solo se puede reabrir un ticket resuelto o cerrado');
        }

        $from = $ticket->status;

        $fresh = DB::transaction(function () use ($ticket, $reason, $actor) {
            $ticket->update(['resolved_at' => null, 'closed_at' => null]);
            $this->applyStatus($ticket, 'en_proceso', $actor, "Reabierto: {$reason}");
            $this->addMessage($ticket, $actor, $reason, false);

            $this->audit->logClientAction($actor, 'support_ticket.reopened', 'SupportTicket', $ticket->id, null, [
                'reason' => $reason,
            ]);

            return $ticket->fresh();
        });

        TicketStatusChanged::dispatch($ticket->id, $from, 'en_proceso', 'client');

        return $fresh;
    }

    /**
     * Estados terminales sobre los que no se permite operar (asignar/diagnosticar).
     */
    private const TERMINAL_STATUSES = ['cerrado', 'cancelado'];

    /**
     * ¿El ticket está en un estado terminal (no admite más operaciones)?
     */
    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL_STATUSES, true);
    }

    /**
     * @throws DomainException si el ticket está en un estado terminal.
     */
    private function assertNotTerminal(SupportTicket $ticket, string $action): void
    {
        if (self::isTerminal($ticket->status)) {
            throw new DomainException("No se puede {$action} un ticket en estado '{$ticket->status}'");
        }
    }

    /**
     * Aplica el cambio de estado, setea timestamps y registra historial.
     */
    private function applyStatus(SupportTicket $ticket, string $newStatus, Model $actor, ?string $note = null): void
    {
        $from = $ticket->status;

        $payload = ['status' => $newStatus];
        if ($newStatus === 'resuelto' && !$ticket->resolved_at) {
            $payload['resolved_at'] = now();
        }
        if ($newStatus === 'cerrado' && !$ticket->closed_at) {
            $payload['closed_at'] = now();
        }

        $ticket->update($payload);
        $this->recordStatus($ticket, $from, $newStatus, $actor, $note);
    }

    private function recordStatus(SupportTicket $ticket, ?string $from, string $to, Model $actor, ?string $note): void
    {
        $meta = $this->actorMeta($actor);

        TicketStatusHistory::create([
            'ticket_id' => $ticket->id,
            'from_status' => $from,
            'to_status' => $to,
            'changed_by_type' => $meta['type'],
            'changed_by_id' => $meta['id'],
            'changed_by_name' => $meta['name'],
            'note' => $note,
        ]);
    }

    /**
     * @return array{type: string, id: int, name: string}
     */
    private function actorMeta(Model $actor): array
    {
        $type = $actor instanceof User ? 'user' : 'client';

        return [
            'type' => $type,
            'id' => $actor->id,
            'name' => $actor->name,
        ];
    }
}
