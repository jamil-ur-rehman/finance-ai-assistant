<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AI\ChatResponseBuilder;
use App\Services\Finance\ReceiptOcrService;
use App\Services\Finance\ReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class ReceiptController extends Controller
{
    public function __construct(
        private readonly ReceiptService $receiptService,
        private readonly ReceiptOcrService $receiptOcrService,
        private readonly ChatResponseBuilder $responseBuilder,
    ) {}

    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
                'text' => ['nullable', 'string', 'max:10000'],
                'merchant' => ['nullable', 'string', 'max:255'],
                'amount' => ['nullable', 'numeric', 'min:0'],
                'category' => ['nullable', 'string', 'max:100'],
                'date' => ['nullable', 'date'],
                'currency' => ['nullable', 'string', 'max:3'],
                'description' => ['nullable', 'string', 'max:1000'],
            ]);

            $user = auth()->user();

            if ($user === null) {
                return response()->json(
                    $this->responseBuilder->error('Unauthenticated.'),
                    401
                );
            }

            $ocrSource = null;
            $imagePath = null;
            $text = trim((string) ($validated['text'] ?? ''));

            if ($request->hasFile('image')) {
                $ocr = $this->receiptOcrService->extractFromImage($request->file('image'));
                $text = trim($ocr['text']);
                $ocrSource = $ocr['source'];
                $imagePath = $ocr['image_path'];
            }

            if ($text === '' && empty($validated['amount'])) {
                return response()->json(
                    $this->responseBuilder->error('Upload a receipt photo or provide receipt text.'),
                    422
                );
            }

            if ($text === '') {
                $text = implode("\n", array_filter([
                    isset($validated['merchant']) ? 'Merchant: '.$validated['merchant'] : null,
                    isset($validated['amount']) ? 'Total: $'.$validated['amount'] : null,
                    isset($validated['date']) ? 'Date: '.$validated['date'] : null,
                ]));
            }

            $overrides = array_filter([
                'merchant' => $validated['merchant'] ?? null,
                'amount' => isset($validated['amount']) ? (float) $validated['amount'] : null,
                'category' => $validated['category'] ?? null,
                'date' => $validated['date'] ?? null,
                'currency' => $validated['currency'] ?? null,
                'description' => $validated['description'] ?? null,
            ], fn ($value) => $value !== null && $value !== '');

            $meta = array_filter([
                'ocr_source' => $ocrSource,
                'image_path' => $imagePath,
            ]);

            $result = $this->receiptService->processReceipt($user->id, $text, $overrides, $meta);

            $ocrNote = match ($ocrSource) {
                'vision' => 'Text extracted from your photo using AI vision.',
                'mock' => 'Text simulated from your photo (add OPENAI_API_KEY for real OCR).',
                default => null,
            };

            $message = trim(implode("\n", array_filter([
                sprintf(
                    'Receipt added: %s for $%s in %s.',
                    $result['parsed']['merchant'] ?? 'Unknown merchant',
                    number_format((float) $result['parsed']['amount'], 2),
                    $result['parsed']['category']
                ),
                $ocrNote,
            ])));

            return response()->json(
                $this->responseBuilder->success('spending', $message, [
                    'transaction' => $result['transaction'],
                    'parsed' => $result['parsed'],
                    'extracted_text' => $text,
                    'ocr_source' => $ocrSource,
                ])
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Receipt API failure', [
                'user_id' => auth()->id(),
                'error' => $exception->getMessage(),
            ]);

            return response()->json(
                $this->responseBuilder->error('Something went wrong while processing the receipt. Please try again.'),
                500
            );
        }
    }
}
