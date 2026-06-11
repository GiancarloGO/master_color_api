<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cotizaciones/presupuestos de un ticket (servicios fuera de garantía).
     * Una fila por versión; la vigente es la más reciente.
     */
    public function up(): void
    {
        Schema::create('ticket_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->decimal('labor_cost', 10, 2)->default(0);
            $table->decimal('parts_cost', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->string('currency', 3)->default('PEN');
            $table->enum('status', ['pendiente', 'aprobado', 'rechazado'])->default('pendiente');
            $table->text('note')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['ticket_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_quotes');
    }
};
