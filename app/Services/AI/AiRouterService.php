<?php

namespace App\Services\AI;

use App\Contracts\Ai\LlmClientInterface;
use App\Models\Budget;
use App\Models\User;
use App\Services\Finance\BudgetService;
use App\Services\Finance\FinancialSummaryService;
use App\Services\Finance\InsightService;
use App\Services\Finance\MemoryService;
use App\Services\Finance\MerchantLookupService;
use App\Services\Finance\ReceiptService;
use App\Services\Finance\SpendingService;
use App\Services\Finance\SuggestionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiRouterService
{
    private const VALID_INTENTS = [
        'spending_query',
        'insight_query',
        'budget_query',
        'receipt_query',
        'merchant_lookup',
        'financial_summary',
        'suggestion_query',
        'unknown',
    ];

    public function __construct(
        private readonly LlmClientInterface $llmClient,
        private readonly PromptBuilderService $promptBuilder,
        private readonly RuleBasedIntentClassifier $ruleBasedIntentClassifier,
        private readonly ResponseFormatterService $responseFormatter,
        private readonly MemoryService $memoryService,
        private readonly SpendingService $spendingService,
        private readonly InsightService $insightService,
        private readonly BudgetService $budgetService,
        private readonly ReceiptService $receiptService,
        private readonly MerchantLookupService $merchantLookupService,
        private readonly FinancialSummaryService $financialSummaryService,
        private readonly SuggestionService $suggestionService,
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
        $budgetPlan = $this->tryStoreBudgetPlan($user, $message);

        if ($budgetPlan !== null) {
            return $budgetPlan;
        }

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
            array_merge(
                $this->memoryService->buildContext($user->id),
                ['query' => $routed['parameters'] ?? []]
            )
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
                message: $this->classificationFailureMessage($exception),
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
                message: 'I am not sure what you are asking. I can help with spending totals, financial summaries, merchant lookups, receipts, savings suggestions, and budget questions.'
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
            'insight_query' => $this->insightService->generateInsights(
                $user->id,
                $this->memoryService->spendingFiltersFromContext($memoryContext)
            ),
            'budget_query' => $this->budgetService->getBudgetOverview(
                $user->id,
                $spendingFilters
            ),
            'receipt_query' => $this->processReceiptFromMessage(
                $user->id,
                $message,
                $parameters
            ),
            'merchant_lookup' => $this->merchantLookupService->lookup(
                $this->resolveChargeDescriptor($message, $parameters)
            ),
            'financial_summary' => $this->financialSummaryService->generateSummary(
                $user->id,
                $this->memoryService->spendingFiltersFromContext($memoryContext)
            ),
            'suggestion_query' => $this->suggestionService->generateSuggestions(
                $user->id,
                $this->memoryService->spendingFiltersFromContext($memoryContext)
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
        if (empty(config('services.openai.api_key'))) {
            Log::info('Using rule-based intent classification because OpenAI API key is not configured.');

            return $this->ruleBasedIntentClassifier->classify($message);
        }

        try {
            $rawResponse = $this->llmClient->chat(
                $this->promptBuilder->buildIntentClassificationPrompt(
                    $this->memoryService->formatForPrompt($userId)
                ),
                $message
            );

            return $this->parseClassification($rawResponse);
        } catch (Throwable $exception) {
            Log::warning('LLM intent classification failed, falling back to rule-based classifier.', [
                'user_id' => $userId,
                'error' => $exception->getMessage(),
            ]);

            $fallback = $this->ruleBasedIntentClassifier->classify($message);

            if ($fallback['intent'] !== 'unknown') {
                return $fallback;
            }

            throw $exception;
        }
    }

    private function classificationFailureMessage(Throwable $exception): string
    {
        if (str_contains($exception->getMessage(), 'API key is not configured')) {
            return 'The AI service is not configured. Add OPENAI_API_KEY to your .env file, or try a clearer question like "How much did I spend on food last month?"';
        }

        return 'I had trouble understanding your request. Could you rephrase it? For example: "How much did I spend on food last month?"';
    }

    /**
     * @return array{success: bool, data: array<string, mixed>}|null
     */
    private function tryStoreBudgetPlan(User $user, string $message): ?array
    {
        if (! preg_match('/\bbudget\b.*\bwill be\b[^0-9]*\$?\s*([\d,]+(?:\.\d+)?)/i', $message, $matches)) {
            return null;
        }

        $limitAmount = (float) str_replace(',', '', $matches[1]);
        $category = $this->extractBudgetCategory($message) ?? 'general';
        $month = preg_match('/\bn+ext\s+month\b/i', $message)
            ? Carbon::now()->addMonth()->format('Y-m')
            : Carbon::now()->format('Y-m');

        Budget::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'category' => $category,
                'month' => $month,
            ],
            [
                'limit_amount' => $limitAmount,
            ]
        );

        return [
            'success' => true,
            'data' => [
                'intent' => 'budget_update',
                'result' => [
                    'category' => $category,
                    'month' => $month,
                    'limit_amount' => $limitAmount,
                ],
                'message' => sprintf(
                    "Got it. I've set your %s budget for %s to $%s.",
                    str_replace('_', ' ', $category),
                    $month,
                    number_format($limitAmount, 2)
                ),
            ],
        ];
    }

    private function extractBudgetCategory(string $message): ?string
    {
        $normalized = strtolower($message);
        $categories = ['food', 'transport', 'rent', 'shopping', 'subscriptions', 'utilities', 'clothing'];

        foreach ($categories as $category) {
            if (preg_match('/\b'.preg_quote($category, '/').'\b/', $normalized)) {
                return $category === 'clothing' ? 'shopping' : $category;
            }
        }

        return null;
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
            'receipt_text' => $this->nullableString($parameters['receipt_text'] ?? null),
            'charge_descriptor' => $this->nullableString($parameters['charge_descriptor'] ?? null),
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
            'receipt_text' => null,
            'charge_descriptor' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function processReceiptFromMessage(int $userId, string $message, array $parameters): array
    {
        $text = $parameters['receipt_text'] ?? $this->extractReceiptText($message);

        if ($text === null || trim($text) === '') {
            return [
                'error' => 'missing_receipt_text',
                'message' => 'Paste the receipt text after "add receipt" so I can parse merchant, amount, and date.',
            ];
        }

        $processed = $this->receiptService->processReceipt($userId, $text);

        return [
            'transaction' => $processed['transaction']->toArray(),
            'parsed' => $processed['parsed'],
        ];
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function resolveChargeDescriptor(string $message, array $parameters): string
    {
        if (! empty($parameters['charge_descriptor'])) {
            return (string) $parameters['charge_descriptor'];
        }

        if (! empty($parameters['merchant'])) {
            return (string) $parameters['merchant'];
        }

        return $this->extractChargeDescriptor($message) ?? trim($message);
    }

    private function extractReceiptText(string $message): ?string
    {
        if (preg_match('/\b(?:add|upload|scan|process)\s+(?:this\s+)?receipt\s*[:\-]?\s*(.+)$/is', $message, $matches)) {
            return trim($matches[1]);
        }

        if (preg_match('/\breceipt\s*[:\-]\s*(.+)$/is', $message, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function extractChargeDescriptor(string $message): ?string
    {
        if (preg_match('/\bwhat is(?: this)?\s+(.+?)(?:\s+charge)?[?.!]*$/i', $message, $matches)) {
            return trim($matches[1]);
        }

        if (preg_match('/\b(?:explain|identify)\s+(?:this\s+)?(?:charge|transaction|payment)\s*[:\-]?\s*(.+)$/i', $message, $matches)) {
            return trim($matches[1]);
        }

        return null;
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
        } elseif ($timeRange === 'this_month') {
            $filters['start_date'] = Carbon::now()->startOfMonth();
            $filters['end_date'] = Carbon::now()->endOfMonth();
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
