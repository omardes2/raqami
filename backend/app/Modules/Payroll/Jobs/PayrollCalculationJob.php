<?php

namespace App\Modules\Payroll\Jobs;

use App\Modules\Payroll\Services\PayrollCalculationService;
use App\Support\Queue\TenantAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued, TenantAware payroll calculation for one run. Captures the tenant at
 * dispatch and re-establishes it around handle() (ApplyTenantContext middleware),
 * so the worker never relies on ambient HTTP tenant state. Idempotent: the service
 * only runs while the run is `calculating` and replaces per-entry results, so a
 * retry or a duplicate dispatch cannot duplicate lines.
 */
class PayrollCalculationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TenantAware;

    public function __construct(public readonly string $runId)
    {
        $this->captureTenantContext();
    }

    public function handle(PayrollCalculationService $service): void
    {
        $service->execute($this->runId);
    }
}
