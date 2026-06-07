<?php

namespace App\Services\Finance;

use App\Models\UserMemory;
use Illuminate\Support\Collection;

class MemoryService
{
    private const MEMORY_TRIGGERS = '/\b(remember|don\'t count|dont count|do not count|ignore|from now on|exclude)\b/i';

    /**
     * @return array{key: string, value: string, type: string}
     */
    public function store(int $userId, string $key, string $value): array
    {
        $value = trim($value);

        if ($key === 'salary_date') {
            $memory = UserMemory::query()->updateOrCreate(
                ['user_id' => $userId, 'key' => $key],
                ['value' => $value]
            );
        } else {
            $memory = UserMemory::query()->firstOrCreate(
                ['user_id' => $userId, 'key' => $key, 'value' => $value],
                ['value' => $value]
            );
        }

        return [
            'key' => $memory->key,
            'value' => $memory->value,
            'type' => $key,
        ];
    }

    /**
     * @return Collection<int, UserMemory>
     */
    public function retrieveAll(int $userId): Collection
    {
        return UserMemory::query()
            ->where('user_id', $userId)
            ->orderBy('key')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{
     *     ignored_categories: array<int, string>,
     *     ignored_merchants: array<int, string>,
     *     salary_date: string|null,
     *     notes: array<int, string>
     * }
     */
    public function buildContext(int $userId): array
    {
        $context = [
            'ignored_categories' => [],
            'ignored_merchants' => [],
            'salary_date' => null,
            'notes' => [],
        ];

        foreach ($this->retrieveAll($userId) as $memory) {
            $value = trim((string) $memory->value);

            if ($value === '') {
                continue;
            }

            match ($memory->key) {
                'ignore_category' => $context['ignored_categories'][] = strtolower($value),
                'ignored_categories' => $this->mergeList($context['ignored_categories'], $value),
                'ignore_merchant' => $context['ignored_merchants'][] = strtolower($value),
                'ignored_merchants' => $this->mergeList($context['ignored_merchants'], $value),
                'salary_date' => $context['salary_date'] = $value,
                'note' => $context['notes'][] = $value,
                default => null,
            };
        }

        $context['ignored_categories'] = array_values(array_unique($context['ignored_categories']));
        $context['ignored_merchants'] = array_values(array_unique($context['ignored_merchants']));
        $context['notes'] = array_values(array_unique($context['notes']));

        return $context;
    }

    /**
     * @return array<int, string>
     */
    public function formatForPrompt(int $userId): array
    {
        $context = $this->buildContext($userId);
        $lines = [];

        foreach ($context['ignored_categories'] as $category) {
            $lines[] = "Ignore category: {$category}";
        }

        foreach ($context['ignored_merchants'] as $merchant) {
            $lines[] = "Ignore merchant: {$merchant}";
        }

        if ($context['salary_date'] !== null) {
            $lines[] = "Salary date: {$context['salary_date']}";
        }

        foreach ($context['notes'] as $note) {
            $lines[] = "Note: {$note}";
        }

        return $lines;
    }

    public function shouldStoreFromMessage(string $message): bool
    {
        return (bool) preg_match(self::MEMORY_TRIGGERS, $message);
    }

    /**
     * @return array{key: string, value: string, type: string}|null
     */
    public function extractAndStore(int $userId, string $message): ?array
    {
        if (! $this->shouldStoreFromMessage($message)) {
            return null;
        }

        $normalized = trim($message);

        if (preg_match('/\b(?:remember\s+)?(?:my\s+)?salary(?:\s+date)?\s*(?:is\s+)?(?:on\s+)?(?:the\s+)?(\d{1,2}(?:st|nd|rd|th)?)\b/i', $normalized, $matches)) {
            return $this->store($userId, 'salary_date', $matches[1]);
        }

        if (preg_match('/\b(?:don\'t count|dont count|do not count|ignore|exclude)\s+(?:my\s+)?(.+?)(?:\s+(?:in|from)\s+(?:my\s+)?(?:expenses|spending|budget)|\s+category|\s+expenses|\s+transactions|$)/i', $normalized, $matches)) {
            return $this->storeIgnoredSubject($userId, $this->normalizeSubject($matches[1]));
        }

        if (preg_match('/\bfrom now on\s+(?:don\'t count|dont count|do not count|ignore|exclude)\s+(?:my\s+)?(.+?)(?:\s+(?:in|from)\s+(?:my\s+)?(?:expenses|spending|budget)|$)/i', $normalized, $matches)) {
            return $this->storeIgnoredSubject($userId, $this->normalizeSubject($matches[1]));
        }

        if (preg_match('/\bremember\s+(?:that\s+)?(.+)/i', $normalized, $matches)) {
            $note = trim($matches[1]);

            if ($this->containsIgnoreInstruction($note)) {
                if (preg_match('/\b(?:don\'t count|dont count|do not count|ignore|exclude)\s+(?:my\s+)?(.+?)(?:\s+(?:in|from)\s+(?:my\s+)?(?:expenses|spending|budget)|\s+category|\s+expenses|\s+transactions|$)/i', $note, $ignoreMatches)) {
                    return $this->storeIgnoredSubject($userId, $this->normalizeSubject($ignoreMatches[1]));
                }
            }

            return $this->store($userId, 'note', $note);
        }

        return null;
    }

    /**
     * @param  array{key: string, value: string, type: string}  $stored
     */
    public function confirmationMessage(array $stored): string
    {
        return match ($stored['type']) {
            'ignore_category' => sprintf("Got it. I'll exclude %s from your spending totals from now on.", $stored['value']),
            'ignore_merchant' => sprintf("Got it. I'll exclude %s expenses from now on.", $stored['value']),
            'salary_date' => sprintf("Got it. I'll remember your salary date is the %s of the month.", $stored['value']),
            'note' => "Got it. I'll keep that preference in mind for future answers.",
            default => 'Got it. I saved that preference.',
        };
    }

    /**
     * @return array{exclude_categories?: array<int, string>, exclude_merchants?: array<int, string>}
     */
    public function spendingFiltersFromContext(array $context): array
    {
        $filters = [];

        if (! empty($context['ignored_categories'])) {
            $filters['exclude_categories'] = $context['ignored_categories'];
        }

        if (! empty($context['ignored_merchants'])) {
            $filters['exclude_merchants'] = $context['ignored_merchants'];
        }

        return $filters;
    }

    /**
     * @return array{key: string, value: string, type: string}
     */
    private function storeIgnoredSubject(int $userId, string $subject): array
    {
        if ($this->looksLikeMerchant($subject)) {
            return $this->store($userId, 'ignore_merchant', $subject);
        }

        return $this->store($userId, 'ignore_category', $subject);
    }

    private function normalizeSubject(string $subject): string
    {
        $subject = trim($subject);
        $subject = preg_replace('/\b(category|categories|expenses|spending|transactions|from my budget)\b/i', '', $subject) ?? $subject;
        $subject = preg_replace('/\s+/', ' ', $subject) ?? $subject;

        return trim($subject);
    }

    private function containsIgnoreInstruction(string $note): bool
    {
        return (bool) preg_match('/\b(don\'t count|dont count|do not count|ignore|exclude)\b/i', $note);
    }

    private function looksLikeMerchant(string $subject): bool
    {
        return (bool) preg_match('/\b(uber|lyft|netflix|spotify|amazon|apple|google)\b/i', $subject)
            || str_word_count($subject) === 1 && preg_match('/^[A-Z]/', $subject);
    }

    /**
     * @param  array<int, string>  $target
     */
    private function mergeList(array &$target, string $value): void
    {
        $decoded = json_decode($value, true);

        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $target[] = strtolower(trim($item));
                }
            }

            return;
        }

        foreach (explode(',', $value) as $item) {
            $item = strtolower(trim($item));

            if ($item !== '') {
                $target[] = $item;
            }
        }
    }
}
