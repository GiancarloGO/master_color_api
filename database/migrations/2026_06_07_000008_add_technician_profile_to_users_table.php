<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Perfil del técnico para asignación inteligente de visitas:
     * - specialties: categorías de ticket que atiende (garantia, instalacion, ...).
     * - coverage_zones: distritos/zonas que cubre.
     * - is_available: si está disponible para recibir nuevas asignaciones.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('specialties')->nullable()->after('is_active');
            $table->json('coverage_zones')->nullable()->after('specialties');
            $table->boolean('is_available')->default(true)->after('coverage_zones');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['specialties', 'coverage_zones', 'is_available']);
        });
    }
};
