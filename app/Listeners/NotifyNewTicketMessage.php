<?php

namespace App\Listeners;

use App\Events\TicketMessageCreated;
use App\Models\TicketMessage;
use App\Services\PushNotificationService;
use Illuminate\Support\Facades\Log;

class NotifyNewTicketMessage
{
    public function __construct(private PushNotificationService $push) {}

    public function handle(TicketMessageCreated $event): void
    {
        try {
            $message = TicketMessage::with(['ticket.client', 'ticket.assignedUser'])->find($event->messageId);
            if (!$message || !$message->ticket) {
                return;
            }

            $ticket = $message->ticket;
            $title = "Ticket {$ticket->code}";

            // Mensaje del staff (público) → avisar al cliente.
            if ($message->author_type === 'user' && !$message->is_internal && $ticket->client) {
                $this->push->sendToModel($ticket->client, $title, 'Tienes una nueva respuesta de soporte', [
                    'ticket_id' => (string) $ticket->id,
                    'type' => 'ticket_message',
                ]);
            }

            // Mensaje del cliente → avisar al técnico asignado.
            if ($message->author_type === 'client' && $ticket->assignedUser) {
                $this->push->sendToModel($ticket->assignedUser, $title, 'El cliente respondió en el ticket', [
                    'ticket_id' => (string) $ticket->id,
                    'type' => 'ticket_message',
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('NotifyNewTicketMessage failed', [
                'message_id' => $event->messageId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
