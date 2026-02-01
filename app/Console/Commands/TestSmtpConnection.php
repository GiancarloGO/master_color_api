<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Config;
use Exception;

class TestSmtpConnection extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mail:test-smtp {email? : Email address to send test email to}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test SMTP connection by sending a test email';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 Verificando configuración SMTP...');
        $this->newLine();

        // Display current configuration
        $this->table(
            ['Configuración', 'Valor'],
            [
                ['MAIL_MAILER', Config::get('mail.default')],
                ['MAIL_HOST', Config::get('mail.mailers.smtp.host')],
                ['MAIL_PORT', Config::get('mail.mailers.smtp.port')],
                ['MAIL_USERNAME', Config::get('mail.mailers.smtp.username')],
                ['MAIL_ENCRYPTION', env('MAIL_ENCRYPTION')],
                ['MAIL_FROM_ADDRESS', Config::get('mail.from.address')],
                ['MAIL_FROM_NAME', Config::get('mail.from.name')],
                ['MAIL_PASSWORD', Config::get('mail.mailers.smtp.password') ? '****** (configurado)' : 'NO CONFIGURADO'],
            ]
        );

        $this->newLine();

        // Validate configuration
        if (!Config::get('mail.mailers.smtp.password')) {
            $this->error('❌ Error: MAIL_PASSWORD no está configurado');
            return 1;
        }

        // Get recipient email
        $recipientEmail = $this->argument('email') ?? Config::get('mail.from.address');

        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            $this->error('❌ Email inválido: ' . $recipientEmail);
            return 1;
        }

        $this->info("📧 Enviando email de prueba a: {$recipientEmail}");
        $this->newLine();

        try {
            $startTime = microtime(true);

            // Send test email
            Mail::raw(
                "Este es un email de prueba para validar la conexión SMTP.\n\n" .
                "Configuración:\n" .
                "- Host: " . Config::get('mail.mailers.smtp.host') . "\n" .
                "- Puerto: " . Config::get('mail.mailers.smtp.port') . "\n" .
                "- Encriptación: " . env('MAIL_ENCRYPTION') . "\n" .
                "- Usuario: " . Config::get('mail.mailers.smtp.username') . "\n\n" .
                "Fecha y hora: " . now()->format('Y-m-d H:i:s') . "\n\n" .
                "Si recibes este email, la conexión SMTP está funcionando correctamente.",
                function ($message) use ($recipientEmail) {
                    $message->to($recipientEmail)
                            ->subject('✅ Test de Conexión SMTP - Master Color');
                }
            );

            $endTime = microtime(true);
            $duration = round(($endTime - $startTime) * 1000, 2);

            $this->newLine();
            $this->info("✅ Email enviado exitosamente en {$duration}ms");
            $this->info("📬 Verifica la bandeja de entrada de: {$recipientEmail}");
            $this->newLine();
            $this->comment('💡 Nota: Si usas Gmail, revisa también la carpeta de Spam');

            return 0;

        } catch (Exception $e) {
            $this->newLine();
            $this->error('❌ Error al enviar el email:');
            $this->error($e->getMessage());
            $this->newLine();
            
            $this->warn('Posibles causas:');
            $this->line('  1. Credenciales incorrectas (usuario/contraseña)');
            $this->line('  2. Contraseña de aplicación de Gmail no válida');
            $this->line('  3. Verificación en dos pasos no activada en Gmail');
            $this->line('  4. Firewall bloqueando el puerto 587');
            $this->line('  5. Configuración de TLS incorrecta');
            $this->newLine();
            
            $this->info('Para Gmail, asegúrate de:');
            $this->line('  • Tener la verificación en dos pasos activada');
            $this->line('  • Usar una "Contraseña de aplicación" en lugar de tu contraseña normal');
            $this->line('  • Generar contraseña en: https://myaccount.google.com/apppasswords');

            return 1;
        }
    }
}
