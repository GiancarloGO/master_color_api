<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Meses de garantía por defecto para las unidades de este producto.
            // 0 = sin garantía formal.
            $table->unsignedSmallInteger('default_warranty_months')->default(0)->after('unidad');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('default_warranty_months');
        });
    }
};
