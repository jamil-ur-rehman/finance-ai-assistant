<?php

use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\ReceiptController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/chat', [ChatController::class, 'handle']);
    Route::post('/receipt', [ReceiptController::class, 'store']);
});
