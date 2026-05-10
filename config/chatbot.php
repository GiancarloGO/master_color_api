<?php

return [
    'ollama_url'   => env('OLLAMA_URL', 'http://localhost:11434'),
    'model'        => env('OLLAMA_MODEL', 'qwen2.5:3b'),
    'timeout'      => (int) env('OLLAMA_TIMEOUT', 60),
    'max_products' => (int) env('CHATBOT_MAX_PRODUCTS', 50),
    'max_history'  => 6,
];
