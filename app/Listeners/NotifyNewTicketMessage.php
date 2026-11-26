<?php

namespace App\Listeners;

use App\Events\TicketMessageCreated;
use App\Listeners\Concerns\NotifiesSupportAdmins;
use App\Models\TicketMessage;
use App\Services\PushNotificationService;
use Illuminate\Support\Facades\Log;

class NotifyNewTicketMessage
{
    use NotifiesSupportAdmins;

    public function __construct(private PushNotificationService $push) {}

    public function handle(TicketMessageCreated $event): void
    {
        try {
            $message = TicketMessage::with(['ticket.client', 'ticket.assignedUser'])->find($event->messageId);
            if (!$message || !$message->ticket) {
                return;
            }

            $ticket = $message->ticket;
            $authorName = $message->author_name ?? 'Soporte';
            $title = "Nuevo mensaje de {$authorName}";
            $bodyExtract = \Illuminate\Support\Str::limit($message->body, 50);

            // Mensaje del staff (público) → avisar al cliente.
            if ($message->author_type === 'user' && !$message->is_internal && $ticket->client) {
                $this->push->sendToModel($ticket->client, $title, $bodyExtract, [
                    'ticket_id' => (string) $ticket->id,
                    'type' => 'ticket_message',
                ]);
            }

            // Mensaje del cliente → avisar al técnico asignado y a los administradores activos.
            if ($message->author_type === 'client') {
                $payload = [
                    'ticket_id' => (string) $ticket->id,
                    'type' => 'ticket_message',
                ];

                $notifiedIds = [];
                if ($ticket->assignedUser) {
                    $this->push->sendToModel($ticket->assignedUser, $title, $bodyExtract, $payload);
                    $notifiedIds[] = $ticket->assignedUser->id;
                }

                $this->notifyActiveAdmins($title, $bodyExtract, $payload, $notifiedIds);
            }
        } catch (\Throwable $e) {
            Log::error('NotifyNewTicketMessage failed', [
                'message_id' => $event->messageId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
