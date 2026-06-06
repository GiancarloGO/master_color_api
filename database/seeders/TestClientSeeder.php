<?php

namespace Database\Seeders;

use App\Models\Client;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TestClientSeeder extends Seeder
{
    /**
     * Cliente de prueba (is_test = true). Cuando MERCADOPAGO_ALLOW_SIMULATION
     * está activo, los pagos de este cliente se aprueban de forma simulada,
     * sin pasar por el checkout de MercadoPago.
     */
    public function run(): void
    {
        Client::updateOrCreate(
            ['email' => 'cliente.test@mastercolor.com'],
            [
                'name' => 'Cliente De Prueba',
                'password' => Hash::make('cliente1234'),
                'client_type' => 'individual',
                'document_type' => 'DNI',
                'identity_document' => '70000001',
                'phone' => '910000001',
                'email_verified_at' => now(),
                'is_test' => true,
            ]
        );
    }
}
