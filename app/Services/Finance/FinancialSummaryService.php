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
     *     top_categories: array<int, array{name: string, amount: float}>,
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

        $topCategories = $this->topCategories($byCategory, 3);
        $spikes = $this->insightService->detectCategorySpikes($userId, $filters);
        $subscriptions = $this->insightService->detectSubscriptions($userId, $filters);

        $summary = $this->buildSummaryText(
            $totalSpend,
            $topCategories,
            $spikes,
            count($subscriptions)
        );

        return [
            'total_spending_this_month' => $totalSpend,
            'top_categories' => $topCategories,
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
     * @param  array<int, array{name: string, amount: float}>  $topCategories
     * @param  array<int, array<string, mixed>>  $spikes
     */
    private function buildSummaryText(float $totalSpend, array $topCategories, array $spikes, int $subscriptionCount): string
    {
        $lines = [];

        $lines[] = sprintf(
            'This month you have spent $%s so far.',
            number_format($totalSpend, 2)
        );

        if ($topCategories !== []) {
            $parts = array_map(
                fn (array $category) => sprintf('%s ($%s)', $category['name'], number_format($category['amount'], 2)),
                $topCategories
            );
            $lines[] = 'Top categories: '.implode(', ', $parts).'.';
        }

        if ($spikes !== []) {
            $spikeNames = array_map(
                fn (array $spike) => (string) ($spike['category'] ?? 'unknown'),
                array_slice($spikes, 0, 2)
            );
            $lines[] = 'Unusual spikes detected in: '.implode(', ', $spikeNames).'.';
        } else {
            $lines[] = 'No major category spikes compared to last month.';
        }

        $lines[] = sprintf(
            'You have %d recurring subscription%s on record.',
            $subscriptionCount,
            $subscriptionCount === 1 ? '' : 's'
        );

        return implode(' ', $lines);
    }
}
