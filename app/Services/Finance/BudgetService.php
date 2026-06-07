<?php

namespace App\Services\Finance;

class BudgetService
{
    /**
     * @param  array{category?: string|null, start_date?: string|\DateTimeInterface|null, end_date?: string|\DateTimeInterface|null}  $filters
     * @return array<string, mixed>
     */
    public function getBudgetOverview(int $userId, array $filters = []): array
    {
        return [
            'budgets' => [],
            'message' => 'Budget overview is not yet implemented.',
        ];
    }
}
