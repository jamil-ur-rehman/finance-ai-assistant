<?php

namespace App\Services\AI;

use App\Contracts\Ai\LlmClientInterface;
use App\Models\User;
use App\Services\Finance\BudgetService;
use App\Services\Finance\InsightService;
use App\Services\Finance\MemoryService;
use App\Services\Finance\SpendingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiRouterService
{
    private const VALID_INTENTS = [
        'spending_query',
        'insight_query',
        'budget_query',
        'unknown',
    ];

    public function __construct(
        private readonly LlmClientInterface $llmClient,
        private readonly PromptBuilderService $promptBuilder,
        private readonly ResponseFormatterService $responseFormatter,
        private readonly MemoryService $memoryService,
        private readonly SpendingService $spendingService,
        private readonly InsightService $insightService,
        private readonly BudgetService $budgetService,
    ) {}

    /**
     * @return array{
     *     success: bool,
     *     data?: array{
     *         intent: string,
     *         result: array<string, mixed>|null,
     *         insights?: array<string, mixed>,
     *         message: string,
     *         breakdown?: array<string, mixed>,
     *         suggestions?: array<int, string>
     *     },
     *     error?: string
     * }
     */
    public function handle(User $user, string $message): array
    {
        if ($this->memoryService->shouldStoreFromMessage($message)) {
            $stored = $this->memoryService->extractAndStore($user->id, $message);

            if ($stored !== null) {
                return [
                    'success' => true,
                    'data' => [
                        'intent' => 'memory_update',
                        'result' => $stored,
                        'message' => $this->memoryService->confirmationMessage($stored),
                    ],
                ];
            }
        }

        $routed = $this->route($user, $message);

        if (! $routed['success']) {
            return [
                'success' => false,
                'error' => $routed['message'] ?? 'I could not process that request. Please try again with a clearer question.',
            ];
        }

        $result = is_array($routed['result']) ? $routed['result'] : [];
        $insights = $routed['intent'] === 'insight_query' ? $result : [];

        $formatted = $this->responseFormatter->format(
            $routed['intent'],
            $result,
            $insights,
            $this->memoryService->buildContext($user->id)
        );

        $data = [
            'intent' => $routed['intent'],
            'result' => $routed['result'],
            'message' => $formatted['message'],
        ];

        if (isset($formatted['breakdown'])) {
            $data['breakdown'] = $formatted['breakdown'];
        }

        if (! empty($formatted['suggestions'])) {
            $data['suggestions'] = $formatted['suggestions'];
        }

        if ($routed['intent'] === 'insight_query') {
            $data['insights'] = $result;
        }

        return [
            'success' => true,
            'data' => $data,
        ];
    }

    /**
     * @return array{
     *     success: bool,
     *     intent: string,
     *     confidence: float,
     *     parameters: array<string, mixed>,
     *     result: array<string, mixed>|null,
     *     message: string|null
     * }
     */
    public function route(User $user, string $message): array
    {
        $memoryContext = $this->memoryService->buildContext($user->id);

        try {
            $classification = $this->classifyIntent($message, $user->id);
        } catch (Throwable $exception) {
            Log::warning('AI intent classification failed', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);

            return $this->fallbackResponse(
                intent: 'unknown',
                confidence: 0.0,
                message: 'I had trouble understanding your request. Could you rephrase it? For example: "How much did I spend on food last month?"'
            );
        }

        $intent = $classification['intent'];
        $confidence = $classification['confidence'];
        $parameters = $classification['parameters'];

        if ($intent === 'unknown' || $confidence < 0.5) {
            return $this->fallbackResponse(
                intent: 'unknown',
                confidence: $confidence,
                parameters: $parameters,
                message: 'I am not sure what you are asking. I can help with spending totals, financial insights, and budget questions.'
            );
        }

        $spendingFilters = array_merge(
            $this->resolveSpendingFilters($parameters),
            $this->memoryService->spendingFiltersFromContext($memoryContext)
        );

        $result = match ($intent) {
            'spending_query' => $this->spendingService->getAnalytics(
                $user->id,
                $spendingFilters
            ),
            'insight_query' => $this->insightService->generateInsights($user->id),
            'budget_query' => $this->budgetService->getBudgetOverview(
                $user->id,
                $spendingFilters
            ),
            default => null,
        };

        return [
            'success' => true,
            'intent' => $intent,
            'confidence' => $confidence,
            'parameters' => $parameters,
            'result' => $result,
            'message' => null,
        ];
    }

    /**
     * @return array{intent: string, confidence: float, parameters: array<string, mixed>}
     */
    private function classifyIntent(string $message, int $userId): array
    {
        $rawResponse = $this->llmClient->chat(
            $this->promptBuilder->buildIntentClassificationPrompt(
                $this->memoryService->formatForPrompt($userId)
            ),
            $message
        );

        return $this->parseClassification($rawResponse);
    }

    /**
     * @return array{intent: string, confidence: float, parameters: array<string, mixed>}
     */
    private function parseClassification(string $rawResponse): array
    {
        $payload = json_decode($this->extractJson($rawResponse), true);

        if (! is_array($payload)) {
            throw new \InvalidArgumentException('LLM response is not valid JSON.');
        }

        $intent = $payload['intent'] ?? 'unknown';

        if (! in_array($intent, self::VALID_INTENTS, true)) {
            $intent = 'unknown';
        }

        $confidence = isset($payload['confidence'])
            ? max(0.0, min(1.0, (float) $payload['confidence']))
            : 0.0;

        $parameters = is_array($payload['parameters'] ?? null)
            ? $this->normalizeParameters($payload['parameters'])
            : $this->defaultParameters();

        return [
            'intent' => $intent,
            'confidence' => $confidence,
            'parameters' => $parameters,
        ];
    }

    private function extractJson(string $rawResponse): string
    {
        $content = trim($rawResponse);

        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $content, $matches)) {
            return trim($matches[1]);
        }

        return $content;
    }

    /**
     * @return array{category: null|string, time_range: null|string, merchant: null|string, query_type: null|string, start_date?: null|string, end_date?: null|string}
     */
    private function normalizeParameters(array $parameters): array
    {
        $normalized = array_merge($this->defaultParameters(), [
            'category' => $this->nullableString($parameters['category'] ?? null),
            'time_range' => $this->nullableString($parameters['time_range'] ?? null),
            'merchant' => $this->nullableString($parameters['merchant'] ?? null),
            'query_type' => $this->nullableString($parameters['query_type'] ?? null),
        ]);

        if (array_key_exists('start_date', $parameters)) {
            $normalized['start_date'] = $this->nullableString($parameters['start_date']);
        }

        if (array_key_exists('end_date', $parameters)) {
            $normalized['end_date'] = $this->nullableString($parameters['end_date']);
        }

        return $normalized;
    }

    /**
     * @return array{category: null, time_range: null, merchant: null, query_type: null}
     */
    private function defaultParameters(): array
    {
        return [
            'category' => null,
            'time_range' => null,
            'merchant' => null,
            'query_type' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{category?: string, start_date?: \DateTimeInterface, end_date?: \DateTimeInterface}
     */
    private function resolveSpendingFilters(array $parameters): array
    {
        $filters = [];

        if (! empty($parameters['category'])) {
            $filters['category'] = $parameters['category'];
        }

        $timeRange = $parameters['time_range'] ?? null;

        if ($timeRange === 'last_month') {
            $filters['start_date'] = Carbon::now()->subMonth()->startOfMonth();
            $filters['end_date'] = Carbon::now()->subMonth()->endOfMonth();
        } elseif ($timeRange === 'last_7_days') {
            $filters['start_date'] = Carbon::now()->subDays(7)->startOfDay();
            $filters['end_date'] = Carbon::now()->endOfDay();
        } elseif ($timeRange === 'custom') {
            if (! empty($parameters['start_date'])) {
                $filters['start_date'] = Carbon::parse($parameters['start_date'])->startOfDay();
            }

            if (! empty($parameters['end_date'])) {
                $filters['end_date'] = Carbon::parse($parameters['end_date'])->endOfDay();
            }
        }

        return $filters;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{
     *     success: bool,
     *     intent: string,
     *     confidence: float,
     *     parameters: array<string, mixed>,
     *     result: null,
     *     message: string
     * }
     */
    private function fallbackResponse(
        string $intent,
        float $confidence,
        ?string $message = null,
        array $parameters = [],
    ): array {
        return [
            'success' => false,
            'intent' => $intent,
            'confidence' => $confidence,
            'parameters' => $parameters ?: $this->defaultParameters(),
            'result' => null,
            'message' => $message ?? 'I could not process that request. Please try again with a clearer question.',
        ];
    }
}
