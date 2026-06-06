<?php

namespace App\Console\Commands;

use App\Models\SoldUnit;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RecalculateUnitWarranty extends Command
{
    /**
     * Resincroniza la garantía de las sold_units con el default_warranty_months
     * actual de su producto. Pensado para usarse tras un cambio de política de
     * garantías (no es parte del flujo normal: la garantía es un snapshot fijo
     * al momento de la venta).
     *
     * --only-empty: solo recalcula unidades sin garantía (warranty_months = 0
     *               o sin fecha de vencimiento), sin pisar las que ya tienen.
     * --dry-run:    solo reporta, no escribe.
     */
    protected $signature = 'units:recalc-warranty
        {--only-empty : Solo recalcular unidades sin garantía actual}
        {--dry-run : No escribe, solo reporta}';

    protected $description = 'Recalcula warranty_months y warranty_expires_at de las unidades según el producto';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $onlyEmpty = (bool) $this->option('only-empty');

        $this->info('Recálculo de garantías de unidades');
        if ($onlyEmpty) {
            $this->line('Alcance: solo unidades sin garantía actual.');
        }
        if ($dryRun) {
            $this->warn('Modo DRY-RUN: no se escribirá nada.');
        }

        $query = SoldUnit::with('product');

        if ($onlyEmpty) {
            $query->where(function ($q) {
                $q->where('warranty_months', 0)->orWhereNull('warranty_expires_at');
            });
        }

        $units = $query->orderBy('id')->get();

        if ($units->isEmpty()) {
            $this->info('No hay unidades para recalcular.');
            return self::SUCCESS;
        }

        $changed = 0;

        foreach ($units as $unit) {
            $months = (int) ($unit->product->default_warranty_months ?? 0);
            $purchase = $unit->purchase_date instanceof Carbon
                ? $unit->purchase_date->copy()
                : Carbon::parse($unit->purchase_date);

            $expiry = $months > 0 ? $purchase->copy()->addMonths($months) : null;

            $oldMonths = (int) $unit->warranty_months;
            $oldExpiry = optional($unit->warranty_expires_at)->toDateString();
            $newExpiry = optional($expiry)->toDateString();

            if ($oldMonths === $months && $oldExpiry === $newExpiry) {
                continue; // sin cambios
            }

            $changed++;
            $this->line(sprintf(
                '  unit#%d (%s) -> %dm/%s  =>  %dm/%s',
                $unit->id,
                $unit->product->category ?? '?',
                $oldMonths,
                $oldExpiry ?? 'null',
                $months,
                $newExpiry ?? 'null'
            ));

            if (!$dryRun) {
                $unit->update([
                    'warranty_months' => $months,
                    'warranty_expires_at' => $expiry,
                ]);
            }
        }

        $this->newLine();
        $resumen = $dryRun
            ? "Unidades que cambiarían: {$changed} de {$units->count()} revisadas."
            : "Unidades actualizadas: {$changed} de {$units->count()} revisadas.";
        $this->info($resumen);

        return self::SUCCESS;
    }
}
