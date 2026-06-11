<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Campos para soporte de visitas a domicilio:
     * - service_type: distingue el flujo (remoto / domicilio / taller).
     * - service_address_id: dirección a la que va el técnico (solo domicilio).
     */
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->enum('service_type', ['remoto', 'domicilio', 'taller'])
                ->default('remoto')
                ->after('channel');
            $table->foreignId('service_address_id')
                ->nullable()
                ->after('service_type')
                ->constrained('addresses')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_address_id');
            $table->dropColumn('service_type');
        });
    }
};
