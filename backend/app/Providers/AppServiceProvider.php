<?php

namespace App\Providers;

use App\Modules\Ai\Contracts\AiProvider;
use App\Modules\Ai\Contracts\AnthropicAiProvider;
use App\Modules\Ai\Contracts\NullAiProvider;
use App\Modules\Authorization\Services\AccessService;
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

        // One AccessService per request/worker lifecycle so its per-request memo
        // of role assignments (ADR-015) is shared across the request — a list
        // endpoint that checks access per row (e.g. attendance records) then
        // resolves each user's grants once instead of once per row. The memo
        // self-invalidates on tenant change and on assignment writes.
        $this->app->singleton(AccessService::class);

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
