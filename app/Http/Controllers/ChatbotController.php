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

        ChatLog::create([
            'session_id' => $sessionId,
            'role'       => 'user',
            'message'    => $userMsg,
            'ip_address' => $request->ip(),
        ]);

        try {
            $reply = $this->chatbot->reply($history, $userMsg);

            ChatLog::create([
                'session_id' => $sessionId,
                'role'       => 'assistant',
                'message'    => $reply,
                'ip_address' => $request->ip(),
            ]);

            return response()->json(['success' => true, 'reply' => $reply]);
        } catch (\Exception $e) {
            Log::error('AI provider error', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'reply'   => 'Lo siento, el asistente no está disponible en este momento. Por favor intenta en unos minutos o contáctanos directamente.',
            ], 503);
        }
    }
}
