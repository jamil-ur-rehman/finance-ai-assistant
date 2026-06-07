<?php

namespace App\Services\AI;

use App\Contracts\Ai\LlmClientInterface;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiLlmClient implements LlmClientInterface
{
    public function chat(string $systemPrompt, string $userMessage): string
    {
        $apiKey = config('services.openai.api_key');

        if (empty($apiKey)) {
            throw new RuntimeException('OpenAI API key is not configured.');
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout((int) config('services.openai.timeout', 30))
                ->post($this->endpoint('/chat/completions'), [
                    'model' => config('services.openai.model', 'gpt-4o-mini'),
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userMessage],
                    ],
                    'temperature' => 0,
                    'response_format' => ['type' => 'json_object'],
                ]);

            $response->throw();
        } catch (RequestException $exception) {
            throw new RuntimeException(
                'LLM request failed: '.$exception->getMessage(),
                previous: $exception
            );
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('LLM returned an empty response.');
        }

        return trim($content);
    }

    private function endpoint(string $path): string
    {
        return rtrim(config('services.openai.base_url', 'https://api.openai.com/v1'), '/').$path;
    }
}
