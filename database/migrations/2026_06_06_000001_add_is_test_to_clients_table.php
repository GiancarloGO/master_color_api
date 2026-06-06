<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marca de cliente de prueba. Los pedidos de estos clientes pueden aprobar
     * el pago de forma simulada (sin pasar por MercadoPago) cuando el flag
     * MERCADOPAGO_ALLOW_SIMULATION está activo.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('is_test')->default(false)->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('is_test');
        });
    }
};
