<?php

namespace App\Services\Finance;

use Carbon\Carbon;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ReceiptOcrService
{
    /**
     * @return array{text: string, source: string, image_path: string|null}
     */
    public function extractFromImage(UploadedFile $file): array
    {
        $imagePath = $file->store('receipts', 'local');

        try {
            $text = $this->extractWithVisionApi($file);

            if ($text !== null) {
                return [
                    'text' => $text,
                    'source' => 'vision',
                    'image_path' => $imagePath,
                ];
            }
        } catch (Throwable $exception) {
            Log::warning('Vision OCR failed, falling back to mock OCR.', [
                'error' => $exception->getMessage(),
            ]);
        }

        return [
            'text' => $this->mockExtract($file),
            'source' => 'mock',
            'image_path' => $imagePath,
        ];
    }

    private function extractWithVisionApi(UploadedFile $file): ?string
    {
        $apiKey = config('services.openai.api_key');

        if (empty($apiKey)) {
            return null;
        }

        $mimeType = $file->getMimeType() ?: 'image/jpeg';
        $base64 = base64_encode((string) file_get_contents($file->getRealPath()));
        $dataUri = 'data:'.$mimeType.';base64,'.$base64;

        try {
            $response = Http::withToken($apiKey)
                ->timeout((int) config('services.openai.timeout', 30))
                ->post($this->openAiEndpoint('/chat/completions'), [
                    'model' => config('services.openai.model', 'gpt-4o-mini'),
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You extract text from receipt images. Return ONLY the raw receipt text as plain text lines (merchant, items if visible, total, date). No markdown or JSON.',
                        ],
                        [
                            'role' => 'user',
                            'content' => [
                                [
                                    'type' => 'text',
                                    'text' => 'Extract all text from this receipt image.',
                                ],
                                [
                                    'type' => 'image_url',
                                    'image_url' => ['url' => $dataUri],
                                ],
                            ],
                        ],
                    ],
                    'temperature' => 0,
                ]);

            $response->throw();
        } catch (RequestException $exception) {
            throw new RuntimeException(
                'Vision OCR request failed: '.$exception->getMessage(),
                previous: $exception
            );
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            return null;
        }

        return trim($content);
    }

    /**
     * Simulated OCR for environments without a vision API key.
     * Uses filename hints and returns plausible receipt text for parsing.
     */
    private function mockExtract(UploadedFile $file): string
    {
        $filename = strtolower($file->getClientOriginalName());
        $merchant = $this->guessMerchantFromFilename($filename);
        $amount = $this->guessAmountFromFilename($filename);
        $date = Carbon::now()->toDateString();

        return implode("\n", array_filter([
            $merchant,
            'Total: $'.number_format($amount, 2),
            'Date: '.$date,
            'Source: simulated OCR',
        ]));
    }

    private function guessMerchantFromFilename(string $filename): string
    {
        $merchants = [
            'starbucks' => 'Starbucks',
            'mcdonalds' => 'McDonalds',
            'mcd' => 'McDonalds',
            'kfc' => 'KFC',
            'uber' => 'Uber',
            'amazon' => 'Amazon',
            'walmart' => 'Walmart',
            'target' => 'Target',
        ];

        foreach ($merchants as $keyword => $label) {
            if (str_contains($filename, $keyword)) {
                return $label;
            }
        }

        $basename = pathinfo($filename, PATHINFO_FILENAME);
        $basename = str_replace(['-', '_', 'receipt', 'img', 'scan'], ' ', $basename);
        $basename = trim(preg_replace('/\s+/', ' ', $basename) ?? '');

        return $basename !== '' ? ucwords($basename) : 'Receipt Store';
    }

    private function guessAmountFromFilename(string $filename): float
    {
        if (preg_match('/(\d+)[._-](\d{2})\b/', $filename, $matches)) {
            return (float) ($matches[1].'.'.$matches[2]);
        }

        if (preg_match('/\b(\d{2,4})\b/', $filename, $matches)) {
            $value = (int) $matches[1];

            if ($value >= 100) {
                return round($value / 100, 2);
            }

            if ($value > 0 && $value < 500) {
                return (float) $value;
            }
        }

        return 19.99;
    }

    private function openAiEndpoint(string $path): string
    {
        return rtrim(config('services.openai.base_url', 'https://api.openai.com/v1'), '/').$path;
    }
}
