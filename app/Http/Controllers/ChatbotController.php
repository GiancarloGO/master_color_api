<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChatbotRequest;
use App\Models\ChatLog;
use App\Services\ChatbotService;
use App\Services\Chatbot\ToolChatbotService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ChatbotController extends Controller
{
    public function __construct(private ChatbotService $chatbot, private ToolChatbotService $toolChatbot) {}

    public function message(ChatbotRequest $request)
    {
        $data      = $request->validated();
        $userMsg   = $data['message'];
        $sessionId = $data['session_id'];

        // El historial se guarda en el servidor: el que envía el cliente se ignora
        // para que nadie pueda falsificar turnos del asistente (inyección de prompt).
        $cacheKey = "chatbot:history:{$sessionId}";
        $history  = Cache::get($cacheKey, []);

        $this->logConversation($sessionId, 'user', $userMsg, $request->ip());

        try {
            $reply = config('chatbot.mode') === 'tools'
                ? $this->toolChatbot->reply($history, $userMsg)
                : $this->chatbot->reply($history, $userMsg);

            $this->logConversation($sessionId, 'assistant', $reply, $request->ip());

            $history[] = ['role' => 'user', 'content' => $userMsg];
            $history[] = ['role' => 'assistant', 'content' => $reply];
            Cache::put($cacheKey, array_slice($history, -config('chatbot.max_history')), now()->addHours(2));

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
