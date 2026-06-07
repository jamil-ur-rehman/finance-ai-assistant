<?php

namespace App\Services\AI;

class RuleBasedIntentClassifier
{
    /**
     * @return array{intent: string, confidence: float, parameters: array<string, mixed>}
     */
    public function classify(string $message): array
    {
        $normalized = strtolower(trim($message));

        $parameters = [
            'category' => null,
            'time_range' => null,
            'merchant' => null,
            'query_type' => null,
        ];

        $parameters['category'] = $this->extractCategory($normalized);
        $parameters['time_range'] = $this->extractTimeRange($normalized);
        $parameters['merchant'] = $this->extractMerchant($normalized);
        $parameters['query_type'] = $this->extractQueryType($normalized, $parameters);

        $intent = $this->detectIntent($normalized);

        return [
            'intent' => $intent,
            'confidence' => $intent === 'unknown' ? 0.3 : 0.82,
            'parameters' => $parameters,
        ];
    }

    private function detectIntent(string $message): string
    {
        if (preg_match('/\b(budget will be|set my budget|next budget)\b/', $message)) {
            return 'unknown';
        }

        if (preg_match('/\b(budget|over budget|under budget|budget limit|remaining budget)\b/', $message)) {
            return 'budget_query';
        }

        if (preg_match('/\b(unusual|anomaly|anomalies|subscription|subscriptions|recurring|compare|comparison|trend|pattern|insight)\b/', $message)) {
            return 'insight_query';
        }

        if (preg_match('/\b(spend|spent|spending|expense|expenses|how much|total|breakdown)\b/', $message)) {
            return 'spending_query';
        }

        return 'unknown';
    }

    private function extractCategory(string $message): ?string
    {
        $aliases = [
            'clothing' => 'shopping',
            'clothes' => 'shopping',
            'groceries' => 'food',
            'grocery' => 'food',
        ];

        foreach ($aliases as $term => $category) {
            if (preg_match('/\b'.preg_quote($term, '/').'\b/', $message)) {
                return $category;
            }
        }

        $categories = ['food', 'transport', 'rent', 'shopping', 'subscriptions', 'utilities'];

        foreach ($categories as $category) {
            if (preg_match('/\b'.preg_quote($category, '/').'\b/', $message)) {
                return $category;
            }
        }

        return null;
    }

    private function extractTimeRange(string $message): ?string
    {
        if (preg_match('/\b(last month|previous month)\b/', $message)) {
            return 'last_month';
        }

        if (preg_match('/\b(last 7 days|last seven days|this week|past week|last week)\b/', $message)) {
            return 'last_7_days';
        }

        if (preg_match('/\b(this month|current month)\b/', $message)) {
            return 'this_month';
        }

        return null;
    }

    private function extractMerchant(string $message): ?string
    {
        $merchants = ['uber', 'netflix', 'spotify', 'amazon', 'mcdonalds', 'kfc', 'careem'];

        foreach ($merchants as $merchant) {
            if (str_contains($message, $merchant)) {
                return $merchant;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function extractQueryType(string $message, array $parameters): ?string
    {
        if (preg_match('/\b(budget status|over budget|under budget)\b/', $message)) {
            return 'budget_status';
        }

        if (preg_match('/\b(subscription|subscriptions|recurring)\b/', $message)) {
            return 'subscription';
        }

        if (preg_match('/\b(unusual|anomaly|anomalies)\b/', $message)) {
            return 'anomaly';
        }

        if (preg_match('/\b(compare|comparison|vs|versus)\b/', $message)) {
            return 'comparison';
        }

        if ($parameters['category'] !== null) {
            return 'by_category';
        }

        if (preg_match('/\b(total|overall|how much)\b/', $message)) {
            return 'total';
        }

        return null;
    }
}
