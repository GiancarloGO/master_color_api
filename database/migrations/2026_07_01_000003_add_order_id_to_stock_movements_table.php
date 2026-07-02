<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            // Relación explícita con la orden de venta. Reemplaza el matching
            // frágil por `voucher_number LIKE "VENTA-{id}-%"` en el rollback y en
            // la comprobación de idempotencia.
            $table->foreignId('order_id')
                ->nullable()
                ->after('user_id')
                ->constrained('orders')
                ->nullOnDelete();

            $table->index('order_id');
        });

        $this->backfillOrderId();
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
            $table->dropColumn('order_id');
        });
    }

    /**
     * Backfill de order_id a partir del patrón de voucher `VENTA-{orderId}-...`.
     * Solo se consideran órdenes existentes (evita FKs colgantes).
     */
    private function backfillOrderId(): void
    {
        $movements = DB::table('stock_movements')
            ->whereNull('order_id')
            ->where('voucher_number', 'LIKE', 'VENTA-%')
            ->select('id', 'voucher_number')
            ->get();

        foreach ($movements as $movement) {
            if (!preg_match('/^VENTA-(\d+)-/', $movement->voucher_number, $m)) {
                continue;
            }

            $orderId = (int) $m[1];

            $orderExists = DB::table('orders')->where('id', $orderId)->exists();
            if (!$orderExists) {
                continue;
            }

            DB::table('stock_movements')
                ->where('id', $movement->id)
                ->update(['order_id' => $orderId]);
        }
    }
};
