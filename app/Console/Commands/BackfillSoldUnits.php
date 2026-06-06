<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\SoldUnitService;
use Illuminate\Console\Command;

class BackfillSoldUnits extends Command
{
    /**
     * --status: estados de orden a procesar (CSV). Default: entregado.
     * --dry-run: solo muestra qué haría, sin crear unidades.
     */
    protected $signature = 'units:backfill
        {--status=entregado : Estados de orden a procesar, separados por coma}
        {--dry-run : No crea nada, solo reporta}';

    protected $description = 'Genera sold_units para órdenes ya pagadas que aún no las tienen (idempotente)';

    public function handle(SoldUnitService $soldUnits): int
    {
        $statuses = array_map('trim', explode(',', $this->option('status')));
        $dryRun = (bool) $this->option('dry-run');

        $this->info('Backfill de unidades vendidas');
        $this->line('Estados objetivo: ' . implode(', ', $statuses));
        if ($dryRun) {
            $this->warn('Modo DRY-RUN: no se creará nada.');
        }

        $orders = Order::whereIn('status', $statuses)
            ->whereDoesntHave('soldUnits')
            ->with('orderDetails')
            ->orderBy('id')
            ->get();

        if ($orders->isEmpty()) {
            $this->info('No hay órdenes pendientes de generar unidades. Todo al día.');
            return self::SUCCESS;
        }

        $this->line("Órdenes a procesar: {$orders->count()}");
        $this->newLine();

        $totalUnits = 0;
        $processed = 0;

        foreach ($orders as $order) {
            $expectedUnits = (int) $order->orderDetails->sum('quantity');

            if ($dryRun) {
                $this->line("  [dry] Orden #{$order->id} (status={$order->status}, fecha={$order->created_at->toDateString()}) -> {$expectedUnits} unidades");
                $totalUnits += $expectedUnits;
                $processed++;
                continue;
            }

            // Usar la fecha del pedido como fecha de compra (garantía correcta).
            $created = $soldUnits->generateFromOrder($order, $order->created_at);
            $count = count($created);
            $totalUnits += $count;
            $processed++;

            $this->line("  Orden #{$order->id} (status={$order->status}) -> {$count} unidades creadas");
        }

        $this->newLine();
        $verbo = $dryRun ? 'se crearían' : 'creadas';
        $this->info("Listo. Órdenes procesadas: {$processed}. Unidades {$verbo}: {$totalUnits}.");

        return self::SUCCESS;
    }
}
