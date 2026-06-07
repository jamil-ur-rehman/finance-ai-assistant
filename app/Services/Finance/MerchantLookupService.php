<?php

namespace App\Services\Finance;

use App\Contracts\Ai\LlmClientInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

class MerchantLookupService
{
    /**
     * @var array<int, array{patterns: array<int, string>, merchant: string, explanation: string, category: string, confidence: float}>
     */
    private const KNOWN_DESCRIPTORS = [
        [
            'patterns' => ['stripe', 'stripe.com'],
            'merchant' => 'Stripe',
            'explanation' => 'Stripe is a payment processor. The charge usually comes from a business that uses Stripe to collect payments.',
            'category' => 'shopping',
            'confidence' => 0.78,
        ],
        [
            'patterns' => ['ub*uber', 'uber trip', 'uber *', 'uber.com', 'uber'],
            'merchant' => 'Uber',
            'explanation' => 'This is typically an Uber ride or delivery charge.',
            'category' => 'transport',
            'confidence' => 0.92,
        ],
        [
            'patterns' => ['lyft', 'lyft *'],
            'merchant' => 'Lyft',
            'explanation' => 'This is typically a Lyft ride charge.',
            'category' => 'transport',
            'confidence' => 0.9,
        ],
        [
            'patterns' => ['netflix', 'netflix.com'],
            'merchant' => 'Netflix',
            'explanation' => 'This is a recurring Netflix streaming subscription charge.',
            'category' => 'subscriptions',
            'confidence' => 0.95,
        ],
        [
            'patterns' => ['spotify', 'spotify.com'],
            'merchant' => 'Spotify',
            'explanation' => 'This is a recurring Spotify music subscription charge.',
            'category' => 'subscriptions',
            'confidence' => 0.95,
        ],
        [
            'patterns' => ['amzn', 'amazon', 'amazon.com', 'amzn mktp'],
            'merchant' => 'Amazon',
            'explanation' => 'This is usually an Amazon purchase or marketplace order.',
            'category' => 'shopping',
            'confidence' => 0.9,
        ],
        [
            'patterns' => ['apple.com/bill', 'apple bill', 'itunes'],
            'merchant' => 'Apple',
            'explanation' => 'This is typically an Apple subscription or App Store purchase.',
            'category' => 'subscriptions',
            'confidence' => 0.88,
        ],
        [
            'patterns' => ['paypal', 'pp*'],
            'merchant' => 'PayPal',
            'explanation' => 'PayPal is a payment intermediary. The underlying merchant name may appear after the asterisk.',
            'category' => 'shopping',
            'confidence' => 0.75,
        ],
        [
            'patterns' => ['mcdonalds', 'mcd', 'kfc', 'starbucks'],
            'merchant' => 'Food vendor',
            'explanation' => 'This looks like a food or restaurant purchase.',
            'category' => 'food',
            'confidence' => 0.85,
        ],
        [
            'patterns' => ['careem'],
            'merchant' => 'Careem',
            'explanation' => 'This is typically a Careem ride or delivery charge.',
            'category' => 'transport',
            'confidence' => 0.9,
        ],
    ];

    public function __construct(
        private readonly LlmClientInterface $llmClient,
    ) {}

    /**
     * @return array{
     *     descriptor: string,
     *     likely_merchant: string,
     *     explanation: string,
     *     category: string,
     *     confidence: float,
     *     source: string
     * }
     */
    public function lookup(string $descriptor): array
    {
        $descriptor = trim($descriptor);

        if ($descriptor === '') {
            return $this->unknownResult('unknown charge');
        }

        $normalized = strtolower($descriptor);

        foreach (self::KNOWN_DESCRIPTORS as $entry) {
            foreach ($entry['patterns'] as $pattern) {
                if (str_contains($normalized, strtolower($pattern))) {
                    return [
                        'descriptor' => $descriptor,
                        'likely_merchant' => $entry['merchant'],
                        'explanation' => $entry['explanation'],
                        'category' => $entry['category'],
                        'confidence' => $entry['confidence'],
                        'source' => 'keyword_map',
                    ];
                }
            }
        }

        $llmResult = $this->lookupWithLlm($descriptor);

        if ($llmResult !== null) {
            return $llmResult;
        }

        return $this->unknownResult($descriptor);
    }

    /**
     * @return array{descriptor: string, likely_merchant: string, explanation: string, category: string, confidence: float, source: string}|null
     */
    private function lookupWithLlm(string $descriptor): ?array
    {
        if (empty(config('services.openai.api_key'))) {
            return null;
        }

        try {
            $raw = $this->llmClient->chat(
                <<<'PROMPT'
You identify unknown bank or card charge descriptors for a personal finance assistant.
Return ONLY valid JSON with keys: likely_merchant, explanation, category, confidence.
category must be one of: food, transport, rent, shopping, subscriptions, utilities.
confidence must be between 0 and 1.
PROMPT,
                'What is this charge descriptor: '.$descriptor
            );

            $payload = json_decode(trim($raw), true);

            if (! is_array($payload)) {
                return null;
            }

            return [
                'descriptor' => $descriptor,
                'likely_merchant' => (string) ($payload['likely_merchant'] ?? 'Unknown merchant'),
                'explanation' => (string) ($payload['explanation'] ?? 'I could not confidently identify this charge.'),
                'category' => (string) ($payload['category'] ?? 'shopping'),
                'confidence' => max(0.0, min(1.0, (float) ($payload['confidence'] ?? 0.5))),
                'source' => 'llm',
            ];
        } catch (Throwable $exception) {
            Log::warning('Merchant LLM lookup failed.', ['error' => $exception->getMessage()]);

            return null;
        }
    }

    /**
     * @return array{descriptor: string, likely_merchant: string, explanation: string, category: string, confidence: float, source: string}
     */
    private function unknownResult(string $descriptor): array
    {
        return [
            'descriptor' => $descriptor,
            'likely_merchant' => 'Unknown merchant',
            'explanation' => 'I could not confidently match this descriptor to a known merchant. Check the amount and date against recent purchases.',
            'category' => 'shopping',
            'confidence' => 0.35,
            'source' => 'fallback',
        ];
    }
}
