<?php

namespace App\Services\Finance;

use App\Models\Budget;
use Carbon\Carbon;

class BudgetService
{
    public function __construct(
        private readonly SpendingService $spendingService,
    ) {}

    /**
     * @param  array{category?: string|null, start_date?: string|\DateTimeInterface|null, end_date?: string|\DateTimeInterface|null, exclude_categories?: array<int, string>, exclude_merchants?: array<int, string>}  $filters
     * @return array{budgets: array<int, array<string, mixed>>}
     */
    public function getBudgetOverview(int $userId, array $filters = []): array
    {
        $month = $this->resolveMonth($filters);

        $query = Budget::query()
            ->where('user_id', $userId)
            ->where('month', $month);

        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        $monthStart = Carbon::parse($month.'-01')->startOfMonth();
        $monthEnd = Carbon::parse($month.'-01')->endOfMonth();

        $spendingFilters = array_merge($filters, [
            'start_date' => $monthStart,
            'end_date' => $monthEnd,
        ]);

        unset($spendingFilters['category']);

        $budgets = [];

        foreach ($query->get() as $budget) {
            $spent = $this->spendingService->getTotalSpend($userId, array_merge($spendingFilters, [
                'category' => $budget->category,
            ]));

            $limit = (float) $budget->limit_amount;
            $remaining = max($limit - $spent, 0);

            $budgets[] = [
                'category' => $budget->category,
                'limit_amount' => $limit,
                'spent' => $spent,
                'remaining' => $remaining,
                'month' => $budget->month,
                'status' => $this->budgetStatus($limit, $spent),
            ];
        }

        return [
            'budgets' => $budgets,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function resolveMonth(array $filters): string
    {
        if (! empty($filters['start_date'])) {
            return Carbon::parse($filters['start_date'])->format('Y-m');
        }

        return Carbon::now()->format('Y-m');
    }

    private function budgetStatus(float $limit, float $spent): string
    {
        if ($limit <= 0) {
            return 'unknown';
        }

        $usagePercent = ($spent / $limit) * 100;

        if ($usagePercent >= 100) {
            return 'over_budget';
        }

        if ($usagePercent >= 80) {
            return 'near_limit';
        }

        return 'on_track';
    }
}
