<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('client_id')->constrained('clients');
            $table->foreignId('sold_unit_id')->nullable()->constrained('sold_units')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->enum('category', ['garantia', 'instalacion', 'falla', 'consulta', 'otro']);
            $table->enum('priority', ['baja', 'media', 'alta', 'urgente'])->default('media');
            $table->string('subject', 150);
            $table->text('description');
            $table->enum('status', [
                'abierto', 'asignado', 'en_proceso', 'en_espera_cliente', 'resuelto', 'cerrado', 'cancelado',
            ])->default('abierto');
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('channel', ['app', 'web', 'telefono'])->default('app');
            $table->boolean('is_warranty_covered')->nullable();
            $table->timestamp('sla_due_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('rating_comment')->nullable();
            $table->text('diagnosis')->nullable();
            $table->text('parts_used')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'priority']);
            $table->index(['client_id', 'status']);
            $table->index('assigned_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
