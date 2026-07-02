<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\ChatLog;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PruneLogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'logs:prune
                            {--dry-run : Muestra cuántos registros se eliminarían sin borrar}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Purga logs antiguos almacenados en BD (audit_logs y chat_logs) según su política de retención';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $auditDays = (int) config('audit.retention_days', 180);
        $chatDays  = (int) config('chatbot.log_retention_days', 30);

        $totalAudit = $this->prune(AuditLog::class, 'audit_logs', $auditDays, $dryRun);
        $totalChat  = $this->prune(ChatLog::class, 'chat_logs', $chatDays, $dryRun);

        $verb = $dryRun ? 'se eliminarían' : 'eliminados';
        $this->info("Purga completada: {$verb} {$totalAudit} audit_logs y {$totalChat} chat_logs.");

        if (!$dryRun && ($totalAudit > 0 || $totalChat > 0)) {
            Log::info('logs:prune ejecutado', [
                'audit_logs_deleted' => $totalAudit,
                'chat_logs_deleted'  => $totalChat,
                'audit_retention_days' => $auditDays,
                'chat_retention_days'  => $chatDays,
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * Purga registros de un modelo anteriores a la retención indicada.
     * Retención <= 0 desactiva la purga para esa tabla.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     */
    private function prune(string $model, string $label, int $retentionDays, bool $dryRun): int
    {
        if ($retentionDays <= 0) {
            $this->line("{$label}: retención desactivada (retention_days <= 0), se omite.");
            return 0;
        }

        $cutoff = Carbon::now()->subDays($retentionDays);

        $query = $model::where('created_at', '<', $cutoff);
        $count = $query->count();

        if ($count === 0) {
            $this->line("{$label}: sin registros anteriores a {$cutoff->toDateString()}.");
            return 0;
        }

        if ($dryRun) {
            $this->warn("{$label}: {$count} registro(s) anteriores a {$cutoff->toDateString()} (dry-run).");
            return $count;
        }

        // delete() en lotes evita cargar todo en memoria; created_at está indexado.
        $deleted = 0;
        do {
            $batch = $model::where('created_at', '<', $cutoff)->limit(1000)->delete();
            $deleted += $batch;
        } while ($batch > 0);

        $this->warn("{$label}: {$deleted} registro(s) eliminados (anteriores a {$cutoff->toDateString()}).");

        return $deleted;
    }
}
