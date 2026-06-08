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
            'receipt_text' => null,
            'charge_descriptor' => null,
        ];

        $parameters['category'] = $this->extractCategory($normalized);
        $parameters['time_range'] = $this->extractTimeRange($normalized);
        $parameters['merchant'] = $this->extractMerchant($normalized);
        $parameters['receipt_text'] = $this->extractReceiptText($message);
        $parameters['charge_descriptor'] = $this->extractChargeDescriptor($message);
        $parameters['query_type'] = $this->extractQueryType($normalized, $parameters);

        $intent = $this->detectIntent($normalized, $message);

        return [
            'intent' => $intent,
            'confidence' => $intent === 'unknown' ? 0.3 : 0.82,
            'parameters' => $parameters,
        ];
    }

    private function detectIntent(string $message, string $rawMessage = ''): string
    {
        if (preg_match('/\b(budget will be|set my budget|next budget)\b/', $message)) {
            return 'unknown';
        }

        if (preg_match('/\b(add|upload|scan|process)\s+(?:this\s+)?receipt\b/', $message)
            || preg_match('/\breceipt\s*[:\-]/', $message)) {
            return 'receipt_query';
        }

        if (preg_match('/\bwhat is\b/', $message)
            || preg_match('/\b(explain|identify)\s+(?:this\s+)?(?:charge|transaction|payment)\b/', $message)) {
            return 'merchant_lookup';
        }

        if (preg_match('/\b(summarize|summary|overview|financial summary|how am i doing|give me a summary)\b/', $message)) {
            return 'financial_summary';
        }

        if (preg_match('/\b(suggest|recommend|cut expense|reduce spending|save money|where can i cut|ways to save)\b/', $message)) {
            return 'suggestion_query';
        }

        if (preg_match('/\b(more than usual|spending more|higher than normal|higher than usual|am i spending more)\b/', $message)) {
            return 'insight_query';
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
        $merchants = ['uber', 'netflix', 'spotify', 'amazon', 'mcdonalds', 'kfc', 'careem', 'stripe'];

        foreach ($merchants as $merchant) {
            if (str_contains($message, $merchant)) {
                return $merchant;
            }
        }

        return null;
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

        if (preg_match('/\b(more than usual|spending more|higher than usual)\b/', $message)) {
            return 'comparison';
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

    /**
     * @return array{intent: string, confidence: float, parameters: array<string, mixed>}|null
     */
    public function keywordFallback(string $message): ?array
    {
        $normalized = strtolower(trim($message));

        $parameters = [
            'category' => $this->extractCategory($normalized),
            'time_range' => $this->extractTimeRange($normalized) ?? 'this_month',
            'merchant' => $this->extractMerchant($normalized),
            'query_type' => null,
            'receipt_text' => null,
            'charge_descriptor' => null,
        ];

        if (preg_match('/\b(spent|spend|spending|how much|expense|expenses)\b/', $normalized)) {
            $parameters['query_type'] = $parameters['category'] ? 'by_category' : 'total';

            return ['intent' => 'spending_query', 'confidence' => 0.75, 'parameters' => $parameters];
        }

        if (preg_match('/\bbudget\b/', $normalized)) {
            $parameters['query_type'] = 'budget_status';

            return ['intent' => 'budget_query', 'confidence' => 0.75, 'parameters' => $parameters];
        }

        if (preg_match('/\b(balance|summary|overview|summarize)\b/', $normalized)) {
            return ['intent' => 'financial_summary', 'confidence' => 0.75, 'parameters' => $parameters];
        }

        if (preg_match('/\b(suggest|recommend|cut|save)\b/', $normalized)) {
            return ['intent' => 'suggestion_query', 'confidence' => 0.75, 'parameters' => $parameters];
        }

        return null;
    }
}
