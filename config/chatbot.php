<?php

return [
    'provider'    => env('AI_PROVIDER', 'ollama'),
    'max_products' => (int) env('CHATBOT_MAX_PRODUCTS', 50),
    'max_history'  => 6,

    // 'catalog' = flujo original (catálogo completo en el prompt).
    // 'tools'   = LLM con tool calling + búsqueda controlada + validación de cifras (requiere Ollama).
    'mode'          => env('CHATBOT_MODE', 'catalog'),
    'contact_phone' => env('CHATBOT_CONTACT_PHONE', '+51 999 830 565'),
    // Días sin actualizar tras los cuales el dato de un producto se marca como antiguo.
    'stale_days'    => (int) env('CHATBOT_STALE_DAYS', 180),

    // Persistir cada mensaje del chatbot en la tabla `chat_logs`. Desactivado por
    // defecto para evitar saturar la base de datos con tráfico público; las
    // conversaciones se registran igualmente en el canal de log `chatbot` (archivo).
    'persist_logs' => filter_var(env('CHATBOT_PERSIST_LOGS', false), FILTER_VALIDATE_BOOLEAN),

    // Días de retención de `chat_logs` en BD cuando la persistencia está activa.
    'log_retention_days' => (int) env('CHATBOT_LOG_RETENTION_DAYS', 30),

    'providers' => [
        'ollama' => [
            'url'     => env('OLLAMA_URL', 'http://localhost:11434'),
            'model'   => env('OLLAMA_MODEL', 'qwen2.5:3b'),
            'timeout' => (int) env('OLLAMA_TIMEOUT', 60),
        ],
        'gemini' => [
            'api_key' => env('GEMINI_APIKEY'),
            'timeout' => (int) env('GEMINI_TIMEOUT', 30),
            // Se prueban en orden; si uno falla (429, 5xx, vacío) se usa el siguiente.
            'models'  => array_filter(explode(',', env('GEMINI_MODELS',
                'gemini-3.5-flash-lite,gemini-3.5-flash'
            ))),
        ],
        'openrouter' => [
            'api_key' => env('OPENROUTER_API_KEY'),
            'timeout' => (int) env('OPENROUTER_TIMEOUT', 30),
            'models'  => array_filter(explode(',', env('OPENROUTER_MODELS',
                'poolside/laguna-xs.2:free,nvidia/nemotron-3-super-120b-a12b:free'
            ))),
        ],
    ],
];
