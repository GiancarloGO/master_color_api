<?php

namespace App\Listeners;

use App\Events\TicketStatusChanged;
use App\Mail\TicketStatusNotification;
use App\Models\SupportTicket;
use App\Services\PushNotificationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotifyTicketStatusChanged
{
    public function __construct(private PushNotificationService $push) {}

    public function handle(TicketStatusChanged $event): void
    {
        try {
            $ticket = SupportTicket::with(['client', 'assignedUser'])->find($event->ticketId);
            if (!$ticket) {
                return;
            }

            $title = "Ticket {$ticket->code}";
            $body = "Estado actualizado a: {$ticket->status}";

            // Si el cambio lo hizo el staff/sistema, se avisa al cliente.
            if (in_array($event->actorType, ['user', 'system'], true) && $ticket->client) {
                $this->push->sendToModel($ticket->client, $title, $body, [
                    'ticket_id' => (string) $ticket->id,
                    'type' => 'ticket_status',
                ]);

                if ($ticket->client->email) {
                    Mail::to($ticket->client->email)
                        ->send(new TicketStatusNotification($ticket, $event->fromStatus));
                }
            }

            // Si lo hizo el cliente (p. ej. reapertura), se avisa al técnico asignado.
            if ($event->actorType === 'client' && $ticket->assignedUser) {
                $this->push->sendToModel($ticket->assignedUser, $title, $body, [
                    'ticket_id' => (string) $ticket->id,
                    'type' => 'ticket_status',
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('NotifyTicketStatusChanged failed', [
                'ticket_id' => $event->ticketId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
