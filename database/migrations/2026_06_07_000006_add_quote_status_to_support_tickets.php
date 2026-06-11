<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Añade el estado 'en_espera_aprobacion' (presupuesto pendiente de aprobación
     * del cliente) al CHECK constraint del enum `status` en Postgres.
     */
    private array $base = [
        'abierto', 'asignado', 'en_proceso', 'en_espera_cliente',
        'resuelto', 'cerrado', 'cancelado',
    ];

    public function up(): void
    {
        $values = array_merge($this->base, ['en_espera_aprobacion']);
        $this->replaceStatusCheck($values);
    }

    public function down(): void
    {
        $this->replaceStatusCheck($this->base);
    }

    private function replaceStatusCheck(array $values): void
    {
        $list = implode(', ', array_map(fn ($v) => "'{$v}'", $values));

        DB::statement('ALTER TABLE support_tickets DROP CONSTRAINT IF EXISTS support_tickets_status_check');
        DB::statement(
            "ALTER TABLE support_tickets ADD CONSTRAINT support_tickets_status_check CHECK (status::text = ANY (ARRAY[{$list}]::text[]))"
        );
    }
};
