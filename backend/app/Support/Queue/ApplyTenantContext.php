<?php

namespace App\Support\Queue;

use App\Modules\Tenancy\Services\TenantContext;
use Closure;

/**
 * Job middleware that establishes the job's captured tenant context before
 * handling and always clears it afterwards, so tenant state can never leak
 * between jobs on a shared worker.
 */
class ApplyTenantContext
{
    public function handle(object $job, Closure $next): mixed
    {
        $context = app(TenantContext::class);
        $tenantId = method_exists($job, 'tenantContextId') ? $job->tenantContextId() : null;

        try {
            if ($tenantId !== null) {
                $context->setTenant($tenantId);
            } else {
                // No tenant captured => run with a closed context, not ambient.
                $context->clear();
            }

            return $next($job);
        } finally {
            $context->clear();
        }
    }
}
