<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('image_url')->nullable()->change();
            $table->string('barcode')->nullable()->change();
            $table->string('brand')->nullable()->change();
            $table->text('description')->nullable()->change();
            $table->string('presentation')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('image_url')->nullable(false)->change();
            $table->string('barcode')->nullable(false)->change();
            $table->string('brand')->nullable(false)->change();
            $table->text('description')->nullable(false)->change();
            $table->string('presentation')->nullable(false)->change();
        });
    }
};
