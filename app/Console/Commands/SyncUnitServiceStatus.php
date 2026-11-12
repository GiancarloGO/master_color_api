<?php

namespace App\Console\Commands;

use App\Models\SoldUnit;
use App\Models\SupportTicket;
use Illuminate\Console\Command;

class SyncUnitServiceStatus extends Command
{
    /**
     * Recalcula el estado de las unidades según sus tickets: 'en_servicio' si
     * tienen algún ticket abierto, 'activa' si no. Las unidades 'baja' no se
     * tocan. Pensado para corregir datos previos a la sincronización automática.
     *
     * --dry-run: solo reporta, no escribe.
     */
    protected $signature = 'units:sync-service-status
        {--dry-run : No escribe, solo reporta}';

    protected $description = 'Sincroniza el estado en_servicio/activa de las unidades con sus tickets abiertos';

    private const CLOSED_STATUSES = ['resuelto', 'cerrado', 'cancelado'];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Modo DRY-RUN: no se escribirá nada.');
        }

        $openUnitIds = SupportTicket::whereNotNull('sold_unit_id')
            ->whereNotIn('status', self::CLOSED_STATUSES)
            ->pluck('sold_unit_id')
            ->unique()
            ->all();

        $changed = 0;

        SoldUnit::where('status', '!=', 'baja')->orderBy('id')->each(function (SoldUnit $unit) use ($openUnitIds, $dryRun, &$changed) {
            $target = in_array($unit->id, $openUnitIds, true) ? 'en_servicio' : 'activa';
            if ($unit->status === $target) {
                return;
            }

            $this->line("Unidad #{$unit->id}: {$unit->status} → {$target}");
            if (!$dryRun) {
                $unit->update(['status' => $target]);
            }
            $changed++;
        });

        $this->info(($dryRun ? 'Unidades a actualizar: ' : 'Unidades actualizadas: ') . $changed);

        return self::SUCCESS;
    }
}
