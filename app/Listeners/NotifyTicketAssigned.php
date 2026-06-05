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

            $this->push->sendToModel(
                $assignee,
                "Ticket {$ticket->code} asignado",
                "Se te asignó el ticket: {$ticket->subject}",
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
