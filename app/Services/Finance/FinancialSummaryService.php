<?php

namespace App\Services\Finance;

use Carbon\Carbon;

class FinancialSummaryService
{
    public function __construct(
        private readonly SpendingService $spendingService,
        private readonly InsightService $insightService,
    ) {}

    /**
     * @param  array{exclude_categories?: array<int, string>, exclude_merchants?: array<int, string>}  $filters
     * @return array{
     *     total_spending_this_month: float,
     *     top_category: array{name: string, amount: float}|null,
     *     top_categories: array<int, array{name: string, amount: float}>,
     *     transaction_count: int,
     *     simple_insight: string,
     *     unusual_spikes: array<int, array<string, mixed>>,
     *     subscription_count: int,
     *     summary: string,
     *     period: array{start: string, end: string}
     * }
     */
    public function generateSummary(int $userId, array $filters = []): array
    {
        $monthStart = Carbon::now()->startOfMonth();
        $monthEnd = Carbon::now()->endOfMonth();

        $monthFilters = array_merge($filters, [
            'start_date' => $monthStart,
            'end_date' => $monthEnd,
        ]);

        $analytics = $this->spendingService->getAnalytics($userId, $monthFilters);
        $byCategory = $analytics['by_category'] ?? [];
        $totalSpend = (float) ($analytics['total_spend'] ?? 0);
        $transactionCount = $this->spendingService->countTransactions($userId, $monthFilters);

        $topCategories = $this->topCategories($byCategory, 3);
        $topCategory = $topCategories[0] ?? null;
        $spikes = $this->insightService->detectCategorySpikes($userId, $filters);
        $subscriptions = $this->insightService->detectSubscriptions($userId, $filters);
        $simpleInsight = $this->buildSimpleInsight($totalSpend, $transactionCount, $topCategory, $spikes);

        $summary = $this->buildSummaryText(
            $totalSpend,
            $transactionCount,
            $topCategory,
            $simpleInsight
        );

        return [
            'total_spending_this_month' => $totalSpend,
            'top_category' => $topCategory,
            'top_categories' => $topCategories,
            'transaction_count' => $transactionCount,
            'simple_insight' => $simpleInsight,
            'unusual_spikes' => $spikes,
            'subscription_count' => count($subscriptions),
            'summary' => $summary,
            'period' => [
                'start' => $monthStart->toDateString(),
                'end' => $monthEnd->toDateString(),
            ],
        ];
    }

    /**
     * @param  array<string, float>  $byCategory
     * @return array<int, array{name: string, amount: float}>
     */
    private function topCategories(array $byCategory, int $limit): array
    {
        arsort($byCategory);

        $top = [];

        foreach (array_slice($byCategory, 0, $limit, true) as $name => $amount) {
            $top[] = [
                'name' => (string) $name,
                'amount' => round((float) $amount, 2),
            ];
        }

        return $top;
    }

    /**
     * @param  array{name: string, amount: float}|null  $topCategory
     * @param  array<int, array<string, mixed>>  $spikes
     */
    private function buildSimpleInsight(float $totalSpend, int $transactionCount, ?array $topCategory, array $spikes): string
    {
        if ($transactionCount === 0) {
            return 'No transactions recorded yet this month.';
        }

        if ($spikes !== []) {
            $category = (string) ($spikes[0]['category'] ?? 'a category');

            return ucfirst($category).' spending is significantly higher than last month.';
        }

        if ($topCategory !== null) {
            return sprintf(
                'Most of your spending is going to %s this month.',
                $topCategory['name']
            );
        }

        return 'Your spending is tracking normally this month.';
    }

    /**
     * @param  array{name: string, amount: float}|null  $topCategory
     */
    private function buildSummaryText(float $totalSpend, int $transactionCount, ?array $topCategory, string $simpleInsight): string
    {
        $lines = [
            sprintf('This month you have spent $%s across %d transaction%s.', number_format($totalSpend, 2), $transactionCount, $transactionCount === 1 ? '' : 's'),
        ];

        if ($topCategory !== null) {
            $lines[] = sprintf(
                'Top category: %s at $%s.',
                $topCategory['name'],
                number_format($topCategory['amount'], 2)
            );
        }

        $lines[] = $simpleInsight;

        return implode(' ', $lines);
    }
}
