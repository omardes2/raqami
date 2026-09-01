<?php

namespace App\Console\Commands;

use App\Modules\Billing\Services\SubscriptionLifecycleProcessor;
use Illuminate\Console\Command;

/**
 * Thin entry point for the billing lifecycle processor (spec §10). Idempotent
 * and safe to run repeatedly; a future scheduler can invoke it. Domain logic
 * lives in SubscriptionLifecycleProcessor (module), not here.
 */
class ProcessBillingLifecycle extends Command
{
    protected $signature = 'billing:process-lifecycle';

    protected $description = 'Process due subscription lifecycle transitions (trial/grace expiry, scheduled cancellation/downgrade).';

    public function handle(SubscriptionLifecycleProcessor $processor): int
    {
        $result = $processor->processDue();

        $this->info(sprintf(
            'Lifecycle processed: trials_expired=%d grace_suspended=%d cancellations=%d downgrades=%d errors=%d',
            $result['trials_expired'], $result['grace_suspended'],
            $result['cancellations'], $result['downgrades'], $result['errors'],
        ));

        return $result['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
