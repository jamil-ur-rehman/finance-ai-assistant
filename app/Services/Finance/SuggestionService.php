<?php

namespace App\Services\Finance;

use Carbon\Carbon;

class SuggestionService
{
    public function __construct(
        private readonly SpendingService $spendingService,
    ) {}

    /**
     * @param  array{exclude_categories?: array<int, string>, exclude_merchants?: array<int, string>}  $filters
     * @return array{
     *     suggestions: array<int, string>,
     *     top_categories: array<int, array{name: string, amount: float, share_percent: float}>,
     *     total_spend: float
     * }
     */
    public function generateSuggestions(int $userId, array $filters = []): array
    {
        $monthFilters = array_merge($filters, [
            'start_date' => Carbon::now()->startOfMonth(),
            'end_date' => Carbon::now()->endOfMonth(),
        ]);

        $analytics = $this->spendingService->getAnalytics($userId, $monthFilters);
        $byCategory = $analytics['by_category'] ?? [];
        $totalSpend = (float) ($analytics['total_spend'] ?? 0);

        $topCategories = $this->rankCategories($byCategory, $totalSpend);
        $suggestions = $this->buildSuggestions($topCategories, $totalSpend);

        return [
            'suggestions' => array_slice($suggestions, 0, 3),
            'top_categories' => array_slice($topCategories, 0, 5),
            'total_spend' => $totalSpend,
        ];
    }

    /**
     * @param  array<string, float>  $byCategory
     * @return array<int, array{name: string, amount: float, share_percent: float}>
     */
    private function rankCategories(array $byCategory, float $totalSpend): array
    {
        arsort($byCategory);

        $ranked = [];

        foreach ($byCategory as $name => $amount) {
            $amount = round((float) $amount, 2);
            $share = $totalSpend > 0 ? round(($amount / $totalSpend) * 100, 1) : 0.0;

            $ranked[] = [
                'name' => (string) $name,
                'amount' => $amount,
                'share_percent' => $share,
            ];
        }

        return $ranked;
    }

    /**
     * @param  array<int, array{name: string, amount: float, share_percent: float}>  $topCategories
     * @return array<int, string>
     */
    private function buildSuggestions(array $topCategories, float $totalSpend): array
    {
        if ($totalSpend <= 0) {
            return [
                'Track your first expenses this month so I can suggest where to optimize.',
                'Set category budgets for food, transport, and subscriptions.',
            ];
        }

        $suggestions = [];

        foreach ($topCategories as $category) {
            $name = strtolower($category['name']);
            $share = $category['share_percent'];

            if ($name === 'food' && $share >= 20) {
                $suggestions[] = 'Reduce food delivery and dine out less — cooking at home could cut food spending meaningfully.';
            }

            if ($name === 'subscriptions' && ($share >= 10 || $category['amount'] >= 1000)) {
                $suggestions[] = 'Review recurring subscriptions and cancel services you no longer use.';
            }

            if ($name === 'transport' && $share >= 15) {
                $suggestions[] = 'Optimize transport by combining trips, using public transit, or limiting ride-hailing during peak hours.';
            }

            if ($name === 'shopping' && $share >= 25) {
                $suggestions[] = 'Set a weekly shopping cap and wait 24 hours before non-essential purchases.';
            }
        }

        if ($suggestions === []) {
            $top = $topCategories[0]['name'] ?? 'your top category';
            $suggestions[] = sprintf('Focus on %s first — it is your largest spending category this month.', $top);
            $suggestions[] = 'Set monthly limits on your top two categories to keep spending predictable.';
        }

        $suggestions[] = 'Check for duplicate subscriptions and unused memberships during your next review.';

        return array_values(array_unique($suggestions));
    }
}
