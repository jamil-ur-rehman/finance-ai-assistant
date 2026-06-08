<?php

namespace App\Services\AI;

class ChatResponseBuilder
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{success: true, data: array{type: string, message: string, data: array<string, mixed>}}
     */
    public function success(string $type, string $message, array $data = []): array
    {
        return [
            'success' => true,
            'data' => [
                'type' => $type,
                'message' => $message,
                'data' => $data,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{success: false, error: string, data: array{type: string, message: string, data: array<string, mixed>}}
     */
    public function error(string $message, array $data = []): array
    {
        return [
            'success' => false,
            'error' => $message,
            'data' => [
                'type' => 'error',
                'message' => $message,
                'data' => $data,
            ],
        ];
    }

    /**
     * @return array{success: true, data: array{type: string, message: string, data: array<string, mixed>}}
     */
    public function unknown(): array
    {
        return $this->success(
            'unknown',
            "I can help you with spending, budgets, insights, or summaries. What would you like to know?\n\n".
            "Try one of these:\n".
            "• How much did I spend last month?\n".
            "• Summarize my finances\n".
            "• Am I over my food budget?\n".
            '• Suggest where I can cut expenses',
            [
                'suggested_actions' => [
                    'How much did I spend last month?',
                    'Summarize my finances',
                    'Am I over my food budget?',
                    'Suggest where I can cut expenses',
                ],
            ]
        );
    }

    public static function intentToType(string $intent): string
    {
        return match ($intent) {
            'spending_query', 'receipt_query' => 'spending',
            'budget_query', 'budget_update' => 'budget',
            'memory_update' => 'memory',
            'insight_query', 'financial_summary', 'suggestion_query', 'merchant_lookup' => 'insight',
            default => 'unknown',
        };
    }

    /**
     * @param  array<string, mixed>  $formatted
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function buildDataPayload(string $intent, array $result, array $formatted, array $extra = []): array
    {
        $payload = [
            'intent' => $intent,
            'result' => $result,
        ];

        if (isset($formatted['breakdown'])) {
            $payload['breakdown'] = $formatted['breakdown'];
        }

        if (! empty($formatted['suggestions'])) {
            $payload['suggestions'] = $formatted['suggestions'];
        }

        if ($intent === 'insight_query' && $result !== []) {
            $payload['insights'] = $result;
        }

        return array_merge($payload, $extra);
    }
}
