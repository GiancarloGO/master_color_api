<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Categorías por defecto que existían hardcodeadas en el frontend.
     * (label => slug) — el campo products.category almacena el slug.
     */
    private array $defaults = [
        'Impresoras'  => 'impresoras',
        'Tintas'      => 'tintas',
        'Tóners'      => 'toners',
        'Papel'       => 'papel',
        'Repuestos'   => 'repuestos',
        'Accesorios'  => 'accesorios',
    ];

    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Se conserva la columna string `category` por compatibilidad con
            // consumidores existentes (app móvil / OpenAPI). category_id es la
            // nueva relación de primera clase.
            $table->foreignId('category_id')
                ->nullable()
                ->after('category')
                ->constrained('categories')
                ->nullOnDelete();
        });

        $this->seedDefaultCategories();
        $this->backfillProductCategoryId();
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropColumn('category_id');
        });
    }

    /**
     * Inserta las categorías por defecto si aún no existen.
     */
    private function seedDefaultCategories(): void
    {
        $now = now();

        foreach ($this->defaults as $name => $slug) {
            $exists = DB::table('categories')->where('slug', $slug)->exists();
            if ($exists) {
                continue;
            }

            DB::table('categories')->insert([
                'name'       => $name,
                'slug'       => $slug,
                'active'     => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Vincula cada producto a una categoría según su string `category` (slug).
     * Si el valor no corresponde a ninguna categoría conocida, se crea una nueva.
     */
    private function backfillProductCategoryId(): void
    {
        $values = DB::table('products')
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->pluck('category');

        foreach ($values as $rawValue) {
            $slug = Str::slug($rawValue);
            if ($slug === '') {
                continue;
            }

            $category = DB::table('categories')->where('slug', $slug)->first();

            if (!$category) {
                $now = now();
                $id = DB::table('categories')->insertGetId([
                    'name'       => Str::title(str_replace('-', ' ', $slug)),
                    'slug'       => $slug,
                    'active'     => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $categoryId = $id;
            } else {
                $categoryId = $category->id;
            }

            DB::table('products')
                ->where('category', $rawValue)
                ->update(['category_id' => $categoryId]);
        }
    }
};
