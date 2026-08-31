<?php

namespace App\Providers;

use App\Modules\Ai\Contracts\AiProvider;
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

        // AI Provider abstraction (ADR-011) — inert default driver only.
        $this->app->bind(AiProvider::class, function () {
            return match (config('ai.default', 'null')) {
                default => new NullAiProvider,
            };
        });
    }

    public function boot(): void
    {
        //
    }
}
