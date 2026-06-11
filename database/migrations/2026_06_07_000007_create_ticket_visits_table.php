<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Visitas en sitio del técnico de campo. Una fila por visita registra el
     * check-in/check-out con geolocalización (para calcular tiempo en sitio y
     * verificar la ubicación) y, al cierre, el reporte de servicio: trabajo
     * realizado, firma de conformidad del cliente y acta en PDF.
     */
    public function up(): void
    {
        Schema::create('ticket_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('technician_id')->constrained('users');

            // Check-in / check-out con geolocalización.
            $table->timestamp('checkin_at')->nullable();
            $table->decimal('checkin_latitude', 10, 7)->nullable();
            $table->decimal('checkin_longitude', 10, 7)->nullable();
            $table->timestamp('checkout_at')->nullable();
            $table->decimal('checkout_latitude', 10, 7)->nullable();
            $table->decimal('checkout_longitude', 10, 7)->nullable();

            // Reporte de servicio / acta de conformidad.
            $table->text('work_done')->nullable();
            $table->string('client_signed_name')->nullable();
            $table->string('signature_path')->nullable();
            $table->string('report_pdf_path')->nullable();
            $table->timestamp('reported_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('ticket_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_visits');
    }
};
