<?php

namespace App\Providers;

use App\Modules\Ai\Contracts\AiProvider;
use App\Modules\Ai\Contracts\AnthropicAiProvider;
use App\Modules\Ai\Contracts\NullAiProvider;
use App\Modules\Billing\Contracts\ManualPaymentGateway;
use App\Modules\Billing\Contracts\PaymentGateway;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One tenant context per request/worker lifecycle.
        $this->app->singleton(TenantContext::class);

        // Payment Gateway abstraction (ADR-010) — inert default driver only.
        $this->app->bind(PaymentGateway::class, function () {
            return match (config('billing.default', 'manual')) {
                default => new ManualPaymentGateway,
            };
        });

        // AI Provider abstraction (ADR-011, ADR-024). DISABLED BY DEFAULT: the
        // 'null' driver is inert. The 'anthropic' driver is config-gated and only
        // active when AI_PROVIDER_DRIVER=anthropic AND an API key is configured.
        $this->app->bind(AiProvider::class, function () {
            return match (config('ai.default', 'null')) {
                'anthropic' => new AnthropicAiProvider(config('ai.providers.anthropic')),
                default => new NullAiProvider,
            };
        });
    }

    public function boot(): void
    {
        //
    }
}
