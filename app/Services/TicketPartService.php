<?php

namespace App\Services;

use App\Models\Stock;
use App\Models\SupportTicket;
use App\Models\TicketPart;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class TicketPartService
{
    public function __construct(
        private AuditService $audit,
        private StockMovementService $stock,
    ) {}

    /**
     * Registrar un repuesto consumido por el ticket, descontando inventario.
     *
     * @throws DomainException si el ticket es terminal o no hay stock suficiente.
     */
    public function addPart(SupportTicket $ticket, int $stockId, int $quantity, ?float $unitCost, User $actor): TicketPart
    {
        if (SupportTicketService::isTerminal($ticket->status)) {
            throw new DomainException("No se puede registrar repuestos en un ticket en estado '{$ticket->status}'");
        }

        return DB::transaction(function () use ($ticket, $stockId, $quantity, $unitCost, $actor) {
            $stock = Stock::findOrFail($stockId);

            if ($stock->quantity < $quantity) {
                throw new DomainException("Stock insuficiente. Disponible: {$stock->quantity}, requerido: {$quantity}");
            }

            $unitCost = $unitCost ?? (float) $stock->purchase_price;

            // Movimiento de salida (descuenta inventario), reutilizando el servicio de stock.
            $movement = $this->stock->createMovement([
                'movement_type' => 'salida',
                'reason' => "SOPORTE - Ticket #{$ticket->id} ({$ticket->code})",
                'voucher_number' => "SOPORTE-{$ticket->id}-" . now()->format('YmdHis'),
                'stocks' => [[
                    'stock_id' => $stockId,
                    'quantity' => $quantity,
                    'unit_price' => $unitCost,
                ]],
            ]);

            $part = TicketPart::create([
                'ticket_id' => $ticket->id,
                'stock_id' => $stockId,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'stock_movement_id' => $movement->id,
            ]);

            $this->audit->logStaffAction($actor, 'support_ticket.part_added', 'TicketPart', $part->id, null, [
                'ticket_id' => $ticket->id,
                'stock_id' => $stockId,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
            ]);

            return $part->load('stock.product');
        });
    }

    /**
     * Quitar un repuesto del ticket, revirtiendo el descuento de inventario.
     */
    public function removePart(TicketPart $part, User $actor): void
    {
        DB::transaction(function () use ($part, $actor) {
            if ($part->stock_movement_id) {
                $movement = $part->stockMovement;
                if ($movement && !$movement->canceled_at) {
                    $this->stock->cancelMovement($movement);
                }
            }

            $this->audit->logStaffAction($actor, 'support_ticket.part_removed', 'TicketPart', $part->id, null, [
                'ticket_id' => $part->ticket_id,
                'stock_id' => $part->stock_id,
                'quantity' => $part->quantity,
            ]);

            $part->delete();
        });
    }
}
