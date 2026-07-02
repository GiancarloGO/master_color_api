<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChatbotRequest;
use App\Models\ChatLog;
use App\Services\ChatbotService;
use Illuminate\Support\Facades\Log;

class ChatbotController extends Controller
{
    public function __construct(private ChatbotService $chatbot) {}

    public function message(ChatbotRequest $request)
    {
        $data      = $request->validated();
        $userMsg   = $data['message'];
        $history   = $data['history'] ?? [];
        $sessionId = $data['session_id'];

        $this->logConversation($sessionId, 'user', $userMsg, $request->ip());

        try {
            $reply = $this->chatbot->reply($history, $userMsg);

            $this->logConversation($sessionId, 'assistant', $reply, $request->ip());

            return response()->json(['success' => true, 'reply' => $reply]);
        } catch (\Exception $e) {
            Log::error('AI provider error', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'reply'   => 'Lo siento, el asistente no está disponible en este momento. Por favor intenta en unos minutos o contáctanos directamente.',
            ], 503);
        }
    }

    /**
     * Registra un mensaje del chatbot. Siempre en el canal de archivo `chatbot`;
     * en BD (`chat_logs`) solo si CHATBOT_PERSIST_LOGS está activo, para no
     * saturar la base de datos con el tráfico público del asistente.
     */
    private function logConversation(string $sessionId, string $role, string $message, ?string $ip): void
    {
        Log::channel('chatbot')->info('chat', [
            'session_id' => $sessionId,
            'role'       => $role,
            'message'    => $message,
            'ip_address' => $ip,
        ]);

        if (config('chatbot.persist_logs')) {
            ChatLog::create([
                'session_id' => $sessionId,
                'role'       => $role,
                'message'    => $message,
                'ip_address' => $ip,
            ]);
        }
    }
}
