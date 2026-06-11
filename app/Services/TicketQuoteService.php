<?php

namespace App\Services;

use App\Models\Client;
use App\Models\SupportTicket;
use App\Models\TicketQuote;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class TicketQuoteService
{
    public function __construct(
        private AuditService $audit,
        private SupportTicketService $tickets,
    ) {}

    /**
     * Crear una cotización para el ticket y dejarlo a la espera de aprobación.
     *
     * @throws DomainException si el ticket está en estado terminal.
     */
    public function createQuote(SupportTicket $ticket, array $data, User $actor): TicketQuote
    {
        if (SupportTicketService::isTerminal($ticket->status)) {
            throw new DomainException("No se puede cotizar un ticket en estado '{$ticket->status}'");
        }

        return DB::transaction(function () use ($ticket, $data, $actor) {
            $laborCost = round((float) ($data['labor_cost'] ?? 0), 2);

            // parts_cost: si no se envía, se calcula de los repuestos registrados.
            $partsCost = isset($data['parts_cost'])
                ? round((float) $data['parts_cost'], 2)
                : round((float) $ticket->parts()->sum(DB::raw('quantity * COALESCE(unit_cost, 0)')), 2);

            $quote = TicketQuote::create([
                'ticket_id' => $ticket->id,
                'labor_cost' => $laborCost,
                'parts_cost' => $partsCost,
                'total' => round($laborCost + $partsCost, 2),
                'currency' => $data['currency'] ?? 'PEN',
                'status' => 'pendiente',
                'note' => $data['note'] ?? null,
                'created_by_user_id' => $actor->id,
            ]);

            // Mover el ticket a "en_espera_aprobacion" si la transición es válida.
            if (SupportTicketService::isValidTransition($ticket->status, 'en_espera_aprobacion')) {
                $this->tickets->changeStatus($ticket, 'en_espera_aprobacion', $actor, 'Presupuesto enviado al cliente');
            }

            $this->audit->logStaffAction($actor, 'support_ticket.quote_created', 'TicketQuote', $quote->id, null, [
                'ticket_id' => $ticket->id,
                'total' => $quote->total,
            ]);

            return $quote;
        });
    }

    /**
     * El cliente aprueba o rechaza la cotización vigente.
     *
     * @throws DomainException si la cotización no está pendiente.
     */
    public function decide(SupportTicket $ticket, TicketQuote $quote, bool $approved, Client $actor): TicketQuote
    {
        if ($quote->status !== 'pendiente') {
            throw new DomainException('La cotización ya fue resuelta');
        }

        return DB::transaction(function () use ($ticket, $quote, $approved, $actor) {
            $quote->update([
                'status' => $approved ? 'aprobado' : 'rechazado',
                'decided_at' => now(),
            ]);

            // Tras la decisión el ticket vuelve a en_proceso (continuar trabajo o recotizar).
            if (SupportTicketService::isValidTransition($ticket->status, 'en_proceso')) {
                $note = $approved ? 'Presupuesto aprobado por el cliente' : 'Presupuesto rechazado por el cliente';
                $this->tickets->changeStatus($ticket, 'en_proceso', $actor, $note);
            }

            $this->audit->logClientAction($actor, 'support_ticket.quote_decided', 'TicketQuote', $quote->id, null, [
                'ticket_id' => $ticket->id,
                'decision' => $approved ? 'aprobado' : 'rechazado',
            ]);

            return $quote->fresh();
        });
    }
}
