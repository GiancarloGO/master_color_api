<?php

namespace App\Console\Commands;

use App\Models\SupportTicket;
use App\Services\PushNotificationService;
use Illuminate\Console\Command;

class SendVisitReminders extends Command
{
    protected $signature = 'support:visit-reminders {--within=60 : Minutos de antelación para recordar}';

    protected $description = 'Envía recordatorios push de las visitas programadas próximas a técnicos y clientes';

    public function handle(PushNotificationService $push): int
    {
        $within = max(1, (int) $this->option('within'));
        $now = now();
        $limit = $now->copy()->addMinutes($within);

        $tickets = SupportTicket::with(['assignedUser', 'client'])
            ->whereNotNull('scheduled_at')
            ->whereNull('reminder_sent_at')
            ->whereNotIn('status', ['resuelto', 'cerrado', 'cancelado'])
            ->whereBetween('scheduled_at', [$now, $limit])
            ->get();

        foreach ($tickets as $ticket) {
            $when = $ticket->scheduled_at->format('H:i');
            $data = [
                'ticket_id' => (string) $ticket->id,
                'type' => 'appointment_reminder',
            ];

            if ($ticket->assignedUser) {
                $push->sendToModel(
                    $ticket->assignedUser,
                    "Visita próxima · {$ticket->code}",
                    "Tienes una visita programada a las {$when}.",
                    $data
                );
            }

            if ($ticket->client) {
                $push->sendToModel(
                    $ticket->client,
                    'Recordatorio de visita',
                    "El técnico llegará alrededor de las {$when}.",
                    $data
                );
            }

            $ticket->update(['reminder_sent_at' => now()]);
        }

        $this->info("Recordatorios de visita enviados: {$tickets->count()}");

        return self::SUCCESS;
    }
}
