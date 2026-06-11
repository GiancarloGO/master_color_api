<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Coordenadas de la dirección, para navegación turn-by-turn y pin en mapa
     * durante visitas a domicilio del técnico de soporte. Nullable: las captura
     * el cliente desde la app (o se geocodifican más adelante).
     */
    public function up(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('reference');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
