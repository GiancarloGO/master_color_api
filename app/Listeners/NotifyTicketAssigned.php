<?php

namespace App\Listeners;

use App\Events\TicketAssigned;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\PushNotificationService;
use Illuminate\Support\Facades\Log;

class NotifyTicketAssigned
{
    public function __construct(private PushNotificationService $push) {}

    public function handle(TicketAssigned $event): void
    {
        try {
            $ticket = SupportTicket::find($event->ticketId);
            $assignee = User::find($event->assigneeId);
            if (!$ticket || !$assignee) {
                return;
            }

            // Asegurarse de tener el cliente cargado
            $ticket->loadMissing('client');
            $clientName = $ticket->client ? $ticket->client->name : 'Cliente Anónimo';

            $this->push->sendToModel(
                $assignee,
                "Ticket Asignado",
                "Se te ha asignado el ticket #{$ticket->code} del cliente {$clientName}.",
                [
                    'ticket_id' => (string) $ticket->id,
                    'type' => 'ticket_assigned',
                ]
            );
        } catch (\Throwable $e) {
            Log::error('NotifyTicketAssigned failed', [
                'ticket_id' => $event->ticketId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
