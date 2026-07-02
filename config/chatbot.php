<?php

return [
    'provider'    => env('AI_PROVIDER', 'ollama'),
    'max_products' => (int) env('CHATBOT_MAX_PRODUCTS', 50),
    'max_history'  => 6,

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
        'openrouter' => [
            'api_key' => env('OPENROUTER_API_KEY'),
            'timeout' => (int) env('OPENROUTER_TIMEOUT', 30),
            'models'  => array_filter(explode(',', env('OPENROUTER_MODELS',
                'poolside/laguna-xs.2:free,nvidia/nemotron-3-super-120b-a12b:free'
            ))),
        ],
    ],
];
