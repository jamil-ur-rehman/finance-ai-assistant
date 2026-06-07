<?php

namespace App\Services\Finance;

use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Str;

class ReceiptService
{
    /**
     * @param  array{merchant?: string|null, amount?: float|null, category?: string|null, date?: string|null, currency?: string|null, description?: string|null}  $overrides
     * @param  array<string, mixed>  $meta
     * @return array{
     *     transaction: Transaction,
     *     parsed: array{merchant: string|null, amount: float, category: string, date: string, currency: string, description: string|null}
     * }
     */
    public function processReceipt(int $userId, string $text, array $overrides = [], array $meta = []): array
    {
        $parsed = $this->parseReceiptText($text);

        foreach ($overrides as $key => $value) {
            if ($value !== null && $value !== '') {
                $parsed[$key] = $value;
            }
        }

        $transaction = $this->createTransaction($userId, $parsed, $meta);

        return [
            'transaction' => $transaction,
            'parsed' => $parsed,
        ];
    }

    /**
     * @return array{merchant: string|null, amount: float, category: string, date: string, currency: string, description: string|null}
     */
    public function parseReceiptText(string $text): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $text) ?: [])));

        $amount = $this->extractAmount($text);
        $merchant = $this->extractMerchant($text, $lines);
        $date = $this->extractDate($text);
        $category = $this->guessCategory($merchant, $text);
        $currency = $this->extractCurrency($text);

        return [
            'merchant' => $merchant,
            'amount' => $amount,
            'category' => $category,
            'date' => $date,
            'currency' => $currency,
            'description' => $this->extractDescription($lines),
        ];
    }

    /**
     * @param  array{merchant?: string|null, amount: float, category: string, date: string, currency: string, description?: string|null}  $parsed
     */
    public function createTransaction(int $userId, array $parsed, array $meta = []): Transaction
    {
        return Transaction::query()->create([
            'user_id' => $userId,
            'amount' => round((float) $parsed['amount'], 2),
            'currency' => $parsed['currency'] ?? 'USD',
            'merchant' => $parsed['merchant'],
            'category' => $parsed['category'],
            'description' => $parsed['description'] ?? 'Receipt import',
            'transaction_date' => Carbon::parse($parsed['date']),
            'is_recurring' => false,
            'is_flagged' => false,
            'meta' => array_merge(['source' => 'receipt'], $meta),
        ]);
    }

    private function extractAmount(string $text): float
    {
        if (preg_match('/\b(?:total|amount due|balance|subtotal)\s*[:\-]?\s*(?:\$|USD\s*)?([\d,]+\.\d{2})/i', $text, $matches)) {
            return (float) str_replace(',', '', $matches[1]);
        }

        if (preg_match('/(?:\$|USD\s*)([\d,]+\.\d{2})/i', $text, $matches)) {
            return (float) str_replace(',', '', $matches[1]);
        }

        if (preg_match('/\b([\d,]+\.\d{2})\b/', $text, $matches)) {
            return (float) str_replace(',', '', $matches[1]);
        }

        return 0.0;
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function extractMerchant(string $text, array $lines): ?string
    {
        if (preg_match('/\b(?:from|merchant|store)\s*[:\-]?\s*(.+)$/im', $text, $matches)) {
            return trim($matches[1]);
        }

        if (preg_match('/^([A-Za-z][A-Za-z0-9&\'\-\s]{1,40}?)(?:\s+(?:total|subtotal|amount|date)\b)/i', $text, $matches)) {
            return trim($matches[1]);
        }

        foreach ($lines as $line) {
            if (preg_match('/^\d/', $line)) {
                continue;
            }

            if (preg_match('/\b(total|subtotal|tax|receipt|thank you|date)\b/i', $line)) {
                continue;
            }

            if (strlen($line) >= 2) {
                return $line;
            }
        }

        return null;
    }

    private function extractDate(string $text): string
    {
        if (preg_match('/\b(?:date)\s*[:\-]?\s*(\d{4}-\d{2}-\d{2})/i', $text, $matches)) {
            return Carbon::parse($matches[1])->toDateString();
        }

        if (preg_match('/\b(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})\b/', $text, $matches)) {
            return Carbon::parse($matches[1])->toDateString();
        }

        if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $text, $matches)) {
            return Carbon::parse($matches[1])->toDateString();
        }

        return Carbon::now()->toDateString();
    }

    private function extractCurrency(string $text): string
    {
        if (preg_match('/\b(USD|EUR|GBP|PKR|CAD|AUD)\b/i', $text, $matches)) {
            return strtoupper($matches[1]);
        }

        return 'USD';
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function extractDescription(array $lines): ?string
    {
        if ($lines === []) {
            return null;
        }

        $summary = implode(' | ', array_slice($lines, 0, 3));

        return Str::limit($summary, 250);
    }

    private function guessCategory(?string $merchant, string $text): string
    {
        $haystack = strtolower(trim(($merchant ?? '').' '.$text));

        $rules = [
            'food' => ['mcdonalds', 'kfc', 'starbucks', 'restaurant', 'cafe', 'grocery', 'food', 'pizza', 'burger'],
            'transport' => ['uber', 'lyft', 'careem', 'shell', 'gas', 'fuel', 'parking', 'metro'],
            'subscriptions' => ['netflix', 'spotify', 'apple.com/bill', 'subscription', 'membership'],
            'shopping' => ['amazon', 'walmart', 'target', 'store', 'shop', 'mall'],
            'utilities' => ['electric', 'water', 'internet', 'utility', 'phone bill'],
            'rent' => ['rent', 'landlord', 'lease'],
        ];

        foreach ($rules as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    return $category;
                }
            }
        }

        return 'shopping';
    }
}
