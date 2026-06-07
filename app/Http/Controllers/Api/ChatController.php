<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AI\AiRouterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class ChatController extends Controller
{
    public function __construct(
        private readonly AiRouterService $aiRouter,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'message' => ['required', 'string', 'max:2000'],
            ]);

            $message = trim($validated['message']);

            if ($message === '') {
                return response()->json([
                    'success' => false,
                    'error' => 'Message cannot be empty.',
                ], 422);
            }

            $user = auth()->user();

            if ($user === null) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthenticated.',
                ], 401);
            }

            $response = $this->aiRouter->handle($user, $message);

            $status = ($response['success'] ?? false) ? 200 : 422;

            return response()->json($response, $status);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Chat API failure', [
                'user_id' => auth()->id(),
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Something went wrong while processing your message. Please try again.',
            ], 500);
        }
    }
}
