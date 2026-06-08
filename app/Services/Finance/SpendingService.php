<?php

namespace App\Services\Finance;

use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SpendingService
{
    /**
     * @param  array{category?: string|null, start_date?: string|\DateTimeInterface|null, end_date?: string|\DateTimeInterface|null}  $filters
     * @return array{total_spend: float, by_category: array<string, float>, by_month: array<string, float>}
     */
    public function getAnalytics(int $userId, array $filters = []): array
    {
        return [
            'total_spend' => $this->getTotalSpend($userId, $filters),
            'by_category' => $this->getSpendByCategory($userId, $filters),
            'by_month' => $this->getSpendByMonth($userId, $filters),
        ];
    }

    /**
     * @param  array{category?: string|null, start_date?: string|\DateTimeInterface|null, end_date?: string|\DateTimeInterface|null}  $filters
     */
    public function getTotalSpend(int $userId, array $filters = []): float
    {
        $total = $this->baseQuery($userId, $filters)->sum('amount');

        return $this->normalizeAmount($total);
    }

    /**
     * @param  array{category?: string|null, start_date?: string|\DateTimeInterface|null, end_date?: string|\DateTimeInterface|null, exclude_categories?: array<int, string>, exclude_merchants?: array<int, string>}  $filters
     */
    public function countTransactions(int $userId, array $filters = []): int
    {
        return $this->baseQuery($userId, $filters)->count();
    }

    /**
     * @param  array{category?: string|null, start_date?: string|\DateTimeInterface|null, end_date?: string|\DateTimeInterface|null}  $filters
     * @return array<string, float>
     */
    public function getSpendByCategory(int $userId, array $filters = []): array
    {
        $categoryExpression = $this->categoryColumnExpression();

        $rows = $this->baseQuery($userId, $filters)
            ->selectRaw("{$categoryExpression} as category, SUM(amount) as total_spend")
            ->groupBy('category')
            ->orderByDesc('total_spend')
            ->get();

        $byCategory = [];

        foreach ($rows as $row) {
            $byCategory[(string) $row->category] = $this->normalizeAmount($row->total_spend);
        }

        return $byCategory;
    }

    /**
     * @param  array{category?: string|null, start_date?: string|\DateTimeInterface|null, end_date?: string|\DateTimeInterface|null}  $filters
     * @return array<string, float>
     */
    public function getSpendByMonth(int $userId, array $filters = []): array
    {
        $monthExpression = $this->monthColumnExpression();

        $rows = $this->baseQuery($userId, $filters)
            ->selectRaw("{$monthExpression} as month, SUM(amount) as total_spend")
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $byMonth = [];

        foreach ($rows as $row) {
            $byMonth[(string) $row->month] = $this->normalizeAmount($row->total_spend);
        }

        return $byMonth;
    }

    /**
     * @param  array{category?: string|null, start_date?: string|\DateTimeInterface|null, end_date?: string|\DateTimeInterface|null, exclude_categories?: array<int, string>, exclude_merchants?: array<int, string>}  $filters
     */
    private function baseQuery(int $userId, array $filters): Builder
    {
        $query = Transaction::query()
            ->where('user_id', $userId);

        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (! empty($filters['exclude_categories'])) {
            $excluded = array_map('strtolower', $filters['exclude_categories']);

            $query->where(function (Builder $builder) use ($excluded) {
                $builder->whereNull('category')
                    ->orWhereRaw('LOWER(category) NOT IN ('.implode(',', array_fill(0, count($excluded), '?')).')', $excluded);
            });
        }

        if (! empty($filters['exclude_merchants'])) {
            foreach ($filters['exclude_merchants'] as $merchant) {
                $query->whereRaw('LOWER(COALESCE(merchant, \'\')) NOT LIKE ?', ['%'.strtolower($merchant).'%']);
            }
        }

        if (! empty($filters['start_date'])) {
            $query->where(
                'transaction_date',
                '>=',
                Carbon::parse($filters['start_date'])->startOfDay()
            );
        }

        if (! empty($filters['end_date'])) {
            $query->where(
                'transaction_date',
                '<=',
                Carbon::parse($filters['end_date'])->endOfDay()
            );
        }

        return $query;
    }

    private function categoryColumnExpression(): string
    {
        return "COALESCE(NULLIF(category, ''), 'uncategorized')";
    }

    private function monthColumnExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => "DATE_FORMAT(transaction_date, '%Y-%m')",
            'pgsql' => "TO_CHAR(transaction_date, 'YYYY-MM')",
            'sqlite' => "strftime('%Y-%m', transaction_date)",
            default => "strftime('%Y-%m', transaction_date)",
        };
    }

    private function normalizeAmount(mixed $amount): float
    {
        return round((float) ($amount ?? 0), 2);
    }
}
