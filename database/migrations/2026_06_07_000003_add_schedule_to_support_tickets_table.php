<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Programación de la visita del técnico (agenda de campo).
     */
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->timestamp('scheduled_at')->nullable()->after('service_address_id');
            $table->unsignedSmallInteger('scheduled_window_minutes')->nullable()->after('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn(['scheduled_at', 'scheduled_window_minutes']);
        });
    }
};
