<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marca cuándo se envió el recordatorio push de la visita programada,
     * para que el comando `support:visit-reminders` no lo reenvíe.
     */
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->timestamp('reminder_sent_at')->nullable()->after('scheduled_window_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn('reminder_sent_at');
        });
    }
};
