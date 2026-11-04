<?php

namespace App\Providers;

use App\Contracts\AiProviderInterface;
use App\Services\Ai\GeminiProvider;
use App\Services\Ai\OllamaProvider;
use App\Services\Ai\OpenRouterProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AiProviderInterface::class, function () {
            return match (config('chatbot.provider')) {
                'gemini'     => new GeminiProvider(),
                'openrouter' => new OpenRouterProvider(),
                default      => new OllamaProvider(),
            };
        });
    }

    public function boot(): void
    {
        //
    }
}
