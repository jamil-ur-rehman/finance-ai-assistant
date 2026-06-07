<?php

namespace App\Services\Finance;

use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class InsightService
{
    private const SPIKE_MULTIPLIER = 1.5;

    private const ANOMALY_STD_MULTIPLIER = 2.0;

    public function __construct(
        private readonly SpendingService $spendingService,
    ) {}

    /**
     * @param  array{exclude_categories?: array<int, string>, exclude_merchants?: array<int, string>}  $filters
     * @return array{
     *     anomalies: array<int, array<string, mixed>>,
     *     subscriptions: array<int, array<string, mixed>>,
     *     comparisons: array<int, array<string, mixed>>,
     *     category_spikes: array<int, array<string, mixed>>,
     *     insights: array<int, string>
     * }
     */
    public function generateInsights(int $userId, array $filters = []): array
    {
        $thisMonthFilters = array_merge($filters, [
            'start_date' => Carbon::now()->startOfMonth(),
            'end_date' => Carbon::now()->endOfMonth(),
        ]);

        $lastMonthFilters = array_merge($filters, [
            'start_date' => Carbon::now()->subMonth()->startOfMonth(),
            'end_date' => Carbon::now()->subMonth()->endOfMonth(),
        ]);

        $thisMonth = $this->spendingService->getAnalytics($userId, $thisMonthFilters);
        $lastMonth = $this->spendingService->getAnalytics($userId, $lastMonthFilters);

        $categorySpikes = $this->detectCategorySpikes($userId, $filters);
        $anomalies = $this->detectTransactionAnomalies($userId, $filters);
        $subscriptions = $this->detectSubscriptions($userId, $filters);
        $comparisons = $this->buildComparisons(
            (float) ($thisMonth['total_spend'] ?? 0),
            (float) ($lastMonth['total_spend'] ?? 0),
            $thisMonth['by_category'] ?? [],
            $lastMonth['by_category'] ?? []
        );

        $insights = $this->buildInsightMessages($categorySpikes, $comparisons, $subscriptions);

        return [
            'anomalies' => $anomalies,
            'subscriptions' => $subscriptions,
            'comparisons' => $comparisons,
            'category_spikes' => $categorySpikes,
            'insights' => $insights,
        ];
    }

    /**
     * @param  array{exclude_categories?: array<int, string>, exclude_merchants?: array<int, string>}  $filters
     * @return array<int, array{category: string, current_amount: float, previous_amount: float, change_percent: float, spike: bool}>
     */
    public function detectCategorySpikes(int $userId, array $filters = []): array
    {
        $thisMonthByCategory = $this->spendingService->getSpendByCategory($userId, array_merge($filters, [
            'start_date' => Carbon::now()->startOfMonth(),
            'end_date' => Carbon::now()->endOfMonth(),
        ]));

        $lastMonthByCategory = $this->spendingService->getSpendByCategory($userId, array_merge($filters, [
            'start_date' => Carbon::now()->subMonth()->startOfMonth(),
            'end_date' => Carbon::now()->subMonth()->endOfMonth(),
        ]));

        $categories = array_unique(array_merge(array_keys($thisMonthByCategory), array_keys($lastMonthByCategory)));
        $spikes = [];

        foreach ($categories as $category) {
            $current = (float) ($thisMonthByCategory[$category] ?? 0);
            $previous = (float) ($lastMonthByCategory[$category] ?? 0);

            if ($previous <= 0 || $current <= 0) {
                continue;
            }

            $ratio = $current / $previous;

            if ($ratio >= self::SPIKE_MULTIPLIER) {
                $changePercent = round((($current - $previous) / $previous) * 100, 1);
                $spikes[] = [
                    'category' => $category,
                    'current_amount' => round($current, 2),
                    'previous_amount' => round($previous, 2),
                    'change_percent' => $changePercent,
                    'spike' => true,
                ];
            }
        }

        usort($spikes, fn (array $a, array $b) => ($b['change_percent'] ?? 0) <=> ($a['change_percent'] ?? 0));

        return $spikes;
    }

    /**
     * @param  array{exclude_categories?: array<int, string>, exclude_merchants?: array<int, string>}  $filters
     * @return array<int, array{merchant: string|null, amount: float, category: string|null, transaction_date: string, reason: string}>
     */
    public function detectTransactionAnomalies(int $userId, array $filters = []): array
    {
        $query = Transaction::query()
            ->where('user_id', $userId)
            ->where('transaction_date', '>=', Carbon::now()->subDays(60)->startOfDay());

        if (! empty($filters['exclude_categories'])) {
            $excluded = array_map('strtolower', $filters['exclude_categories']);
            $query->where(function ($builder) use ($excluded) {
                $builder->whereNull('category')
                    ->orWhereRaw('LOWER(category) NOT IN ('.implode(',', array_fill(0, count($excluded), '?')).')', $excluded);
            });
        }

        /** @var Collection<int, Transaction> $transactions */
        $transactions = $query->get();

        if ($transactions->count() < 5) {
            return [];
        }

        $amounts = $transactions->pluck('amount')->map(fn ($amount) => (float) $amount);
        $mean = $amounts->avg();
        $variance = $amounts->map(fn (float $amount) => ($amount - $mean) ** 2)->avg();
        $stdDev = sqrt(max($variance, 0));
        $threshold = $mean + (self::ANOMALY_STD_MULTIPLIER * $stdDev);

        $recent = $transactions->filter(
            fn (Transaction $transaction) => $transaction->transaction_date?->gte(Carbon::now()->subDays(30))
        );

        $anomalies = [];

        foreach ($recent as $transaction) {
            $amount = (float) $transaction->amount;

            if ($amount >= $threshold && $amount > ($mean * 2)) {
                $anomalies[] = [
                    'merchant' => $transaction->merchant,
                    'amount' => round($amount, 2),
                    'category' => $transaction->category,
                    'transaction_date' => $transaction->transaction_date?->toDateString(),
                    'reason' => 'Amount is significantly higher than your typical transaction size.',
                ];
            }
        }

        usort($anomalies, fn (array $a, array $b) => ($b['amount'] ?? 0) <=> ($a['amount'] ?? 0));

        return array_slice($anomalies, 0, 5);
    }

    /**
     * @param  array{exclude_categories?: array<int, string>, exclude_merchants?: array<int, string>}  $filters
     * @return array<int, array{merchant: string, amount: float, frequency: string, category: string|null}>
     */
    public function detectSubscriptions(int $userId, array $filters = []): array
    {
        $query = Transaction::query()
            ->where('user_id', $userId)
            ->where(function ($builder) {
                $builder->where('is_recurring', true)
                    ->orWhereIn('category', ['subscriptions']);
            })
            ->where('transaction_date', '>=', Carbon::now()->subMonths(3)->startOfDay());

        if (! empty($filters['exclude_categories'])) {
            $excluded = array_map('strtolower', $filters['exclude_categories']);
            $query->where(function ($builder) use ($excluded) {
                $builder->whereNull('category')
                    ->orWhereRaw('LOWER(category) NOT IN ('.implode(',', array_fill(0, count($excluded), '?')).')', $excluded);
            });
        }

        $rows = $query->get();

        $grouped = [];

        foreach ($rows as $transaction) {
            $key = strtolower(trim((string) ($transaction->merchant ?? $transaction->description ?? 'unknown')));

            if ($key === '') {
                continue;
            }

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'merchant' => (string) ($transaction->merchant ?? $transaction->description ?? 'Unknown'),
                    'amount' => round((float) $transaction->amount, 2),
                    'frequency' => 'monthly',
                    'category' => $transaction->category,
                    'count' => 0,
                ];
            }

            $grouped[$key]['count']++;
            $grouped[$key]['amount'] = max($grouped[$key]['amount'], round((float) $transaction->amount, 2));
        }

        $subscriptions = array_values(array_filter(
            $grouped,
            fn (array $item) => $item['count'] >= 1
        ));

        usort($subscriptions, fn (array $a, array $b) => ($b['amount'] ?? 0) <=> ($a['amount'] ?? 0));

        return array_map(
            fn (array $item) => [
                'merchant' => $item['merchant'],
                'amount' => $item['amount'],
                'frequency' => $item['frequency'],
                'category' => $item['category'],
            ],
            array_slice($subscriptions, 0, 10)
        );
    }

    /**
     * @param  array<string, float>  $thisMonthCategories
     * @param  array<string, float>  $lastMonthCategories
     * @return array<int, array{label: string, change_percent: float, direction: string, current_amount: float, previous_amount: float}>
     */
    private function buildComparisons(
        float $thisMonthTotal,
        float $lastMonthTotal,
        array $thisMonthCategories,
        array $lastMonthCategories,
    ): array {
        $comparisons = [];

        if ($lastMonthTotal > 0) {
            $changePercent = round((($thisMonthTotal - $lastMonthTotal) / $lastMonthTotal) * 100, 1);
            $comparisons[] = [
                'label' => 'Overall spending',
                'change_percent' => abs($changePercent),
                'direction' => $changePercent >= 0 ? 'increase' : 'decrease',
                'current_amount' => round($thisMonthTotal, 2),
                'previous_amount' => round($lastMonthTotal, 2),
            ];
        }

        foreach ($thisMonthCategories as $category => $current) {
            $previous = (float) ($lastMonthCategories[$category] ?? 0);

            if ($previous <= 0) {
                continue;
            }

            $changePercent = round((($current - $previous) / $previous) * 100, 1);

            if (abs($changePercent) >= 20) {
                $comparisons[] = [
                    'label' => (string) $category,
                    'change_percent' => abs($changePercent),
                    'direction' => $changePercent >= 0 ? 'increase' : 'decrease',
                    'current_amount' => round((float) $current, 2),
                    'previous_amount' => round($previous, 2),
                ];
            }
        }

        return $comparisons;
    }

    /**
     * @param  array<int, array<string, mixed>>  $categorySpikes
     * @param  array<int, array<string, mixed>>  $comparisons
     * @param  array<int, array<string, mixed>>  $subscriptions
     * @return array<int, string>
     */
    private function buildInsightMessages(array $categorySpikes, array $comparisons, array $subscriptions): array
    {
        $messages = [];

        foreach ($comparisons as $comparison) {
            if (($comparison['label'] ?? '') !== 'Overall spending') {
                continue;
            }

            $direction = $comparison['direction'] ?? 'increase';
            $percent = $comparison['change_percent'] ?? 0;
            $messages[] = sprintf(
                'You are spending %s than last month by about %.1f%%.',
                $direction === 'increase' ? 'more' : 'less',
                $percent
            );
            break;
        }

        foreach (array_slice($categorySpikes, 0, 2) as $spike) {
            $messages[] = sprintf(
                '%s spending is up %.1f%% compared to last month ($%s vs $%s).',
                ucfirst((string) ($spike['category'] ?? 'Category')),
                (float) ($spike['change_percent'] ?? 0),
                number_format((float) ($spike['current_amount'] ?? 0), 2),
                number_format((float) ($spike['previous_amount'] ?? 0), 2)
            );
        }

        if ($subscriptions !== []) {
            $messages[] = sprintf('You have %d recurring charges to review.', count($subscriptions));
        }

        return $messages;
    }
}
