<?php

namespace App\Contracts\Ai;

interface LlmClientInterface
{
    public function chat(string $systemPrompt, string $userMessage): string;
}
