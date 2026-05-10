<?php

return [
    'provider'    => env('AI_PROVIDER', 'ollama'),
    'max_products' => (int) env('CHATBOT_MAX_PRODUCTS', 50),
    'max_history'  => 6,

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
