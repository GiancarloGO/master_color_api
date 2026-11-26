<?php

namespace App\Listeners;

use App\Events\TicketCreated;
use App\Listeners\Concerns\NotifiesSupportAdmins;
use App\Models\SupportTicket;
use App\Services\PushNotificationService;
use Illuminate\Support\Facades\Log;

class NotifyNewTicketCreated
{
    use NotifiesSupportAdmins;

    public function __construct(private PushNotificationService $push) {}

    public function handle(TicketCreated $event): void
    {
        try {
            $ticket = SupportTicket::with('client')->find($event->ticketId);
            if (!$ticket) {
                return;
            }

            $clientName = $ticket->client ? $ticket->client->name : 'Cliente Anónimo';

            $this->notifyActiveAdmins(
                'Nuevo Ticket de Soporte',
                "{$clientName} abrió el ticket #{$ticket->code}: {$ticket->subject}",
                [
                    'ticket_id' => (string) $ticket->id,
                    'type' => 'ticket_status',
                ]
            );
        } catch (\Throwable $e) {
            Log::error('NotifyNewTicketCreated failed', [
                'ticket_id' => $event->ticketId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
