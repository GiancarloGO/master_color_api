<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sold_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients');
            $table->foreignId('product_id')->constrained('products');
            // Origen de la unidad: una orden online o registro manual del cliente.
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('order_detail_id')->nullable()->constrained('order_details')->nullOnDelete();
            // Opcional: productos no serializados no lo tienen (soporte mixto).
            $table->string('serial_number')->nullable()->index();
            $table->date('purchase_date');
            $table->unsignedSmallInteger('warranty_months')->default(0);
            $table->date('warranty_expires_at')->nullable();
            $table->enum('registration_source', ['order', 'manual'])->default('order');
            $table->string('proof_file_path')->nullable();
            $table->enum('status', ['activa', 'en_servicio', 'baja'])->default('activa');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sold_units');
    }
};
