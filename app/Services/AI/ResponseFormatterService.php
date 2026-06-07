<?php

namespace App\Services\AI;

class ResponseFormatterService
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $insights
     * @param  array<string, mixed>  $context
     * @return array{message: string, breakdown?: array<string, mixed>, suggestions?: array<int, string>}
     */
    public function format(string $intent, array $data, array $insights = [], array $context = []): array
    {
        return match ($intent) {
            'spending_query' => $this->formatSpending($data, $context),
            'insight_query' => $this->formatInsights($data, $insights, $context),
            'budget_query' => $this->formatBudget($data, $context),
            default => [
                'message' => 'I processed your request, but I do not have a formatted summary for this type yet.',
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $context
     * @return array{message: string, breakdown: array<string, mixed>, suggestions: array<int, string>}
     */
    private function formatSpending(array $data, array $context): array
    {
        $totalSpend = (float) ($data['total_spend'] ?? 0);
        $byCategory = $this->filterIgnoredCategories($data['by_category'] ?? [], $context);
        $byMonth = $data['by_month'] ?? [];
        $requestedCategory = is_string($context['query']['category'] ?? null)
            ? $context['query']['category']
            : null;
        $focusedCategory = count($byCategory) === 1
            ? array_key_first($byCategory)
            : $requestedCategory;

        $lines = [];

        if ($focusedCategory !== null) {
            $categoryAmount = (float) ($byCategory[$focusedCategory] ?? $totalSpend);
            $lines[] = sprintf(
                'You spent %s on %s in this period.',
                $this->formatMoney($categoryAmount),
                $this->formatLabel($focusedCategory)
            );
        } else {
            $lines[] = sprintf('You spent %s overall in this period.', $this->formatMoney($totalSpend));
        }

        $topCategory = $this->topCategory($byCategory);

        if ($topCategory !== null && $focusedCategory === null) {
            $lines[] = sprintf(
                'Your top category was %s at %s.',
                $this->formatLabel($topCategory['name']),
                $this->formatMoney($topCategory['amount'])
            );
        }

        if ($this->hasIgnoredCategories($context)) {
            $lines[] = 'Totals exclude your saved category preferences.';
        }

        $trend = $this->describeMonthlyTrend($byMonth);

        if ($trend !== null) {
            $lines[] = $trend;
        }

        if ($totalSpend === 0.0 && $focusedCategory !== null) {
            $lines = [sprintf(
                'I did not find any spending on %s in this period.',
                $this->formatLabel($focusedCategory)
            )];
        } elseif ($totalSpend === 0.0) {
            $lines = ['I did not find any spending in this period.'];
        }

        $salaryNote = $this->salaryContextNote($context);

        if ($salaryNote !== null) {
            $lines[] = $salaryNote;
        }

        $suggestions = $this->spendingSuggestions($totalSpend, $byCategory, $context);

        return [
            'message' => $this->joinLines($lines),
            'breakdown' => [
                'total_spend' => $this->formatMoney($totalSpend),
                'by_category' => $this->formatCategoryBreakdown($byCategory),
                'by_month' => $this->formatMonthlyBreakdown($byMonth),
            ],
            'suggestions' => $suggestions,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $insights
     * @param  array<string, mixed>  $context
     * @return array{message: string, breakdown: array<string, mixed>, suggestions: array<int, string>}
     */
    private function formatInsights(array $data, array $insights, array $context): array
    {
        $payload = array_merge($data, $insights);
        $lines = [];
        $suggestions = [];

        $anomalies = $payload['anomalies'] ?? [];

        foreach ($anomalies as $anomaly) {
            $lines[] = $this->formatAnomalyLine($anomaly);
        }

        $subscriptions = $payload['subscriptions'] ?? [];

        foreach ($subscriptions as $subscription) {
            $lines[] = $this->formatSubscriptionLine($subscription);
        }

        $comparisons = $payload['comparisons'] ?? [];

        foreach ($comparisons as $comparison) {
            $lines[] = $this->formatComparisonLine($comparison);
        }

        $genericInsights = $payload['insights'] ?? [];

        foreach ($genericInsights as $insight) {
            $lines[] = is_string($insight) ? $insight : ($insight['message'] ?? null);
        }

        $lines = array_values(array_filter($lines, fn ($line) => is_string($line) && $line !== ''));

        if ($lines === []) {
            $lines[] = 'No unusual activity stood out in your recent finances.';
            $lines[] = 'Ask about a specific category or time range if you want a deeper look.';
        }

        if ($this->hasIgnoredCategories($context)) {
            $suggestions[] = 'I skipped categories you asked me to ignore when summarizing patterns.';
        }

        $suggestions[] = 'Review recurring charges monthly to catch price increases early.';

        return [
            'message' => $this->joinLines($lines),
            'breakdown' => [
                'anomalies' => $anomalies,
                'subscriptions' => $subscriptions,
                'comparisons' => $comparisons,
            ],
            'suggestions' => array_values(array_unique($suggestions)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $context
     * @return array{message: string, breakdown: array<string, mixed>, suggestions: array<int, string>}
     */
    private function formatBudget(array $data, array $context): array
    {
        $budgets = $data['budgets'] ?? [];
        $lines = [];
        $warnings = [];
        $suggestions = [];

        if ($budgets === []) {
            return [
                'message' => 'You do not have any budgets set up yet. Create a category budget to track spending against a limit.',
                'breakdown' => ['budgets' => []],
                'suggestions' => [
                    'Start with your highest-spend categories like food, transport, or subscriptions.',
                ],
            ];
        }

        foreach ($budgets as $budget) {
            if (! is_array($budget)) {
                continue;
            }

            $category = $this->formatLabel((string) ($budget['category'] ?? 'uncategorized'));
            $limit = (float) ($budget['limit_amount'] ?? $budget['limit'] ?? 0);
            $spent = (float) ($budget['spent'] ?? 0);
            $remaining = (float) ($budget['remaining'] ?? max($limit - $spent, 0));
            $usagePercent = $limit > 0 ? ($spent / $limit) * 100 : 0;

            $lines[] = sprintf(
                '%s: %s spent of %s limit (%s remaining).',
                $category,
                $this->formatMoney($spent),
                $this->formatMoney($limit),
                $this->formatMoney($remaining)
            );

            if ($usagePercent >= 100) {
                $warnings[] = sprintf('You are over your %s budget by %s.', $category, $this->formatMoney(abs($remaining)));
            } elseif ($usagePercent >= 80) {
                $warnings[] = sprintf('You are close to your %s budget limit.', $category);
            }
        }

        foreach ($warnings as $warning) {
            $lines[] = $warning;
        }

        $salaryNote = $this->salaryContextNote($context);

        if ($salaryNote !== null) {
            $lines[] = $salaryNote;
        }

        if ($warnings !== []) {
            $suggestions[] = 'Consider pausing non-essential purchases in categories that are near or over limit.';
        } else {
            $suggestions[] = 'You are on track — keep monitoring mid-month to avoid end-of-month surprises.';
        }

        return [
            'message' => $this->joinLines($lines),
            'breakdown' => [
                'budgets' => array_map(fn (array $budget) => [
                    'category' => $budget['category'] ?? null,
                    'limit' => $this->formatMoney((float) ($budget['limit_amount'] ?? $budget['limit'] ?? 0)),
                    'spent' => $this->formatMoney((float) ($budget['spent'] ?? 0)),
                    'remaining' => $this->formatMoney((float) ($budget['remaining'] ?? 0)),
                    'status' => $budget['status'] ?? $this->budgetStatus($budget),
                ], array_filter($budgets, 'is_array')),
            ],
            'suggestions' => $suggestions,
        ];
    }

    /**
     * @param  array<string, float|int|string>  $byCategory
     * @param  array<string, mixed>  $context
     * @return array<string, float>
     */
    private function filterIgnoredCategories(array $byCategory, array $context): array
    {
        $ignored = $this->ignoredCategories($context);

        if ($ignored === []) {
            return array_map(fn ($amount) => (float) $amount, $byCategory);
        }

        $filtered = [];

        foreach ($byCategory as $category => $amount) {
            if (! in_array(strtolower((string) $category), $ignored, true)) {
                $filtered[$category] = (float) $amount;
            }
        }

        return $filtered;
    }

    /**
     * @param  array<string, float>  $byCategory
     * @return array{name: string, amount: float}|null
     */
    private function topCategory(array $byCategory): ?array
    {
        if ($byCategory === []) {
            return null;
        }

        arsort($byCategory);
        $name = (string) array_key_first($byCategory);

        return [
            'name' => $name,
            'amount' => (float) $byCategory[$name],
        ];
    }

    /**
     * @param  array<string, float|int|string>  $byMonth
     */
    private function describeMonthlyTrend(array $byMonth): ?string
    {
        if (count($byMonth) < 2) {
            return null;
        }

        ksort($byMonth);
        $amounts = array_values(array_map(fn ($amount) => (float) $amount, $byMonth));
        $previous = $amounts[count($amounts) - 2];
        $latest = $amounts[count($amounts) - 1];

        if ($previous <= 0) {
            return null;
        }

        $changePercent = (($latest - $previous) / $previous) * 100;
        $direction = $changePercent >= 0 ? 'increase' : 'decrease';

        return sprintf(
            'Trend shows a %s of %s compared to the prior month.',
            $direction,
            $this->formatPercent(abs($changePercent))
        );
    }

    /**
     * @param  array<string, float>  $byCategory
     * @param  array<string, mixed>  $context
     * @return array<int, string>
     */
    private function spendingSuggestions(float $totalSpend, array $byCategory, array $context): array
    {
        $suggestions = [];

        if ($totalSpend === 0.0) {
            $suggestions[] = 'Try asking about a wider date range if you expected to see spending here.';

            return $suggestions;
        }

        $topCategory = $this->topCategory($byCategory);

        if ($topCategory !== null) {
            $suggestions[] = sprintf(
                'Consider setting a monthly cap for %s if you want tighter control.',
                $this->formatLabel($topCategory['name'])
            );
        }

        if ($this->hasSalaryDate($context)) {
            $suggestions[] = 'Align big purchases with your salary date to smooth cash flow.';
        }

        return $suggestions;
    }

    private function formatAnomalyLine(mixed $anomaly): string
    {
        if (is_string($anomaly)) {
            return $anomaly;
        }

        if (! is_array($anomaly)) {
            return '';
        }

        $merchant = $anomaly['merchant'] ?? 'a merchant';
        $amount = isset($anomaly['amount']) ? $this->formatMoney((float) $anomaly['amount']) : 'an unusual amount';
        $category = isset($anomaly['category']) ? $this->formatLabel((string) $anomaly['category']) : null;

        if ($category) {
            return sprintf('Unusual spending detected: %s at %s in %s.', $amount, $merchant, $category);
        }

        return sprintf('Unusual spending detected: %s at %s.', $amount, $merchant);
    }

    private function formatSubscriptionLine(mixed $subscription): string
    {
        if (is_string($subscription)) {
            return $subscription;
        }

        if (! is_array($subscription)) {
            return '';
        }

        $name = $subscription['merchant'] ?? $subscription['name'] ?? 'a subscription';
        $amount = isset($subscription['amount']) ? $this->formatMoney((float) $subscription['amount']) : null;
        $frequency = $subscription['frequency'] ?? 'monthly';

        if ($amount !== null) {
            return sprintf('Recurring subscription found: %s at %s (%s).', $name, $amount, $frequency);
        }

        return sprintf('Recurring subscription found: %s (%s).', $name, $frequency);
    }

    private function formatComparisonLine(mixed $comparison): string
    {
        if (is_string($comparison)) {
            return $comparison;
        }

        if (! is_array($comparison)) {
            return '';
        }

        $label = $comparison['label'] ?? 'Spending';
        $changePercent = isset($comparison['change_percent']) ? (float) $comparison['change_percent'] : null;
        $direction = $comparison['direction'] ?? (($changePercent ?? 0) >= 0 ? 'up' : 'down');

        if ($changePercent !== null) {
            return sprintf(
                '%s is %s %s compared to the previous period.',
                $label,
                $direction,
                $this->formatPercent(abs($changePercent))
            );
        }

        return sprintf('%s changed compared to the previous period.', $label);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function salaryContextNote(array $context): ?string
    {
        if (! $this->hasSalaryDate($context)) {
            return null;
        }

        $salaryDate = (string) $context['salary_date'];

        return sprintf('Reminder: your salary typically arrives on day %s of the month.', $salaryDate);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function hasSalaryDate(array $context): bool
    {
        return isset($context['salary_date']) && $context['salary_date'] !== '';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function hasIgnoredCategories(array $context): bool
    {
        return $this->ignoredCategories($context) !== [];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, string>
     */
    private function ignoredCategories(array $context): array
    {
        $ignored = $context['ignored_categories'] ?? [];

        if (is_string($ignored)) {
            $decoded = json_decode($ignored, true);

            $ignored = is_array($decoded)
                ? $decoded
                : array_map('trim', explode(',', $ignored));
        }

        if (! is_array($ignored)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($category) => strtolower(trim((string) $category)),
            $ignored
        )));
    }

    /**
     * @param  array<string, float>  $byCategory
     * @return array<string, string>
     */
    private function formatCategoryBreakdown(array $byCategory): array
    {
        $breakdown = [];

        foreach ($byCategory as $category => $amount) {
            $breakdown[$this->formatLabel((string) $category)] = $this->formatMoney((float) $amount);
        }

        return $breakdown;
    }

    /**
     * @param  array<string, float|int|string>  $byMonth
     * @return array<string, string>
     */
    private function formatMonthlyBreakdown(array $byMonth): array
    {
        $breakdown = [];

        foreach ($byMonth as $month => $amount) {
            $breakdown[(string) $month] = $this->formatMoney((float) $amount);
        }

        return $breakdown;
    }

    /**
     * @param  array<string, mixed>  $budget
     */
    private function budgetStatus(array $budget): string
    {
        $limit = (float) ($budget['limit_amount'] ?? $budget['limit'] ?? 0);
        $spent = (float) ($budget['spent'] ?? 0);

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

    /**
     * @param  array<int, string>  $lines
     */
    private function joinLines(array $lines): string
    {
        return implode("\n", array_slice($lines, 0, 7));
    }

    private function formatMoney(float $amount): string
    {
        return '$'.number_format($amount, 2);
    }

    private function formatPercent(float $percent): string
    {
        return number_format($percent, 1).'%';
    }

    private function formatLabel(string $value): string
    {
        return str_replace('_', ' ', $value);
    }
}
