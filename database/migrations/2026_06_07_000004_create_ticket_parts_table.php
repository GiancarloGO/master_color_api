<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Repuestos consumidos por un ticket de soporte, vinculados al inventario.
     * Cada registro enlaza el movimiento de stock 'salida' que generó, para
     * poder revertirlo si se elimina el repuesto.
     */
    public function up(): void
    {
        Schema::create('ticket_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained('stocks');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 10, 2)->nullable();
            $table->foreignId('stock_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('ticket_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_parts');
    }
};
