<?php

namespace App\Contracts;

interface ToolCallingProviderInterface
{
    /**
     * Una vuelta de conversación con herramientas disponibles.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools  definiciones tipo JSON-schema (formato Ollama/OpenAI)
     * @return array{content: string, tool_calls: array<int, array{name: string, arguments: array<string, mixed>}>}
     */
    public function chatWithTools(array $messages, array $tools): array;
}
