<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use App\Mail\ClientResetPassword;

class DiagnosticController extends Controller
{
    /**
     * Endpoint de diagnóstico para verificar configuración en producción
     * IMPORTANTE: Eliminar este endpoint después de diagnosticar
     */
    public function index()
    {
        $diagnostics = [];

        // 1. Configuración SMTP
        $diagnostics['smtp_config'] = [
            'MAIL_MAILER' => config('mail.default'),
            'MAIL_HOST' => config('mail.mailers.smtp.host'),
            'MAIL_PORT' => config('mail.mailers.smtp.port'),
            'MAIL_USERNAME' => config('mail.mailers.smtp.username'),
            'MAIL_ENCRYPTION' => config('mail.mailers.smtp.encryption'),
            'MAIL_TIMEOUT' => config('mail.mailers.smtp.timeout'),
            'MAIL_FROM_ADDRESS' => config('mail.from.address'),
            'MAIL_FROM_NAME' => config('mail.from.name'),
            'MAIL_PASSWORD_SET' => !empty(config('mail.mailers.smtp.password')),
        ];

        // 2. Variables de entorno
        $diagnostics['env'] = [
            'APP_ENV' => config('app.env'),
            'APP_DEBUG' => config('app.debug'),
            'APP_URL' => config('app.url'),
        ];

        // 3. Últimas líneas del log de Laravel
        $logPath = storage_path('logs/laravel.log');
        if (file_exists($logPath)) {
            $logLines = file($logPath);
            $diagnostics['recent_logs'] = array_slice($logLines, -50);
        } else {
            $diagnostics['recent_logs'] = ['Log file not found'];
        }

        // 4. Test de conexión a base de datos
        try {
            DB::connection()->getPdo();
            $diagnostics['database'] = 'Connected';
        } catch (\Exception $e) {
            $diagnostics['database'] = 'Error: ' . $e->getMessage();
        }

        // 5. Verificar tabla password_resets
        try {
            $diagnostics['password_resets_table'] = DB::table('client_password_resets')->count() . ' records';
        } catch (\Exception $e) {
            $diagnostics['password_resets_table'] = 'Error: ' . $e->getMessage();
        }

        return response()->json($diagnostics, 200, [], JSON_PRETTY_PRINT);
    }

    /**
     * Test de envío de email
     */
    public function testEmail(Request $request)
    {
        $email = $request->input('email', config('mail.from.address'));
        
        try {
            $startTime = microtime(true);
            
            Mail::to($email)->send(new ClientResetPassword(
                'test-token-' . time(),
                $email,
                'Test User'
            ));
            
            $endTime = microtime(true);
            $duration = round(($endTime - $startTime) * 1000, 2);

            return response()->json([
                'status' => 'success',
                'message' => 'Email sent successfully',
                'email' => $email,
                'duration_ms' => $duration
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }
}
