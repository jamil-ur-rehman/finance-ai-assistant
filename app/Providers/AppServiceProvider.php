<?php

namespace App\Providers;

use App\Contracts\Ai\LlmClientInterface;
use App\Services\AI\OpenAiLlmClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LlmClientInterface::class, OpenAiLlmClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
