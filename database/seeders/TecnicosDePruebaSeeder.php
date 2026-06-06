<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TecnicosDePruebaSeeder extends Seeder
{
    /**
     * Crea (de forma idempotente) dos técnicos de prueba con el rol "Tecnico".
     */
    public function run(): void
    {
        $tecnicoRole = Role::firstOrCreate(
            ['name' => 'Tecnico'],
            ['description' => 'Técnico de soporte asignable a tickets']
        );

        $tecnicos = [
            [
                'name' => 'Carlos Técnico',
                'email' => 'tecnico1@mastercolor.com',
                'dni' => '80000001',
                'phone' => '900000001',
            ],
            [
                'name' => 'Lucía Técnica',
                'email' => 'tecnico2@mastercolor.com',
                'dni' => '80000002',
                'phone' => '900000002',
            ],
        ];

        foreach ($tecnicos as $tecnico) {
            User::updateOrCreate(
                ['email' => $tecnico['email']],
                [
                    'name' => $tecnico['name'],
                    'password' => Hash::make('tecnico1234'),
                    'role_id' => $tecnicoRole->id,
                    'dni' => $tecnico['dni'],
                    'phone' => $tecnico['phone'],
                    'is_active' => true,
                ]
            );
        }
    }
}
