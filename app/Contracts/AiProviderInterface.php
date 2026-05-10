<?php

namespace App\Contracts;

interface AiProviderInterface
{
    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    public function chat(array $messages): string;
}
