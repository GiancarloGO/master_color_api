<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class TecnicoRoleSeeder extends Seeder
{
    /**
     * Crea (de forma idempotente) el rol "Tecnico" usado para asignar tickets de soporte.
     */
    public function run(): void
    {
        Role::firstOrCreate(
            ['name' => 'Tecnico'],
            ['description' => 'Técnico de soporte asignable a tickets']
        );
    }
}
