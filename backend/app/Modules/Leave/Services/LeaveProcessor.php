<?php

namespace App\Modules\Leave\Services;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Runs leave accrual and period closure across every tenant, each inside its OWN
 * tenant context so RLS holds. Per-tenant failures are isolated. Idempotent
 * (delegates to the idempotent services). No cron is wired here — this is the
 * callable foundation a scheduler/command invokes.
 */
class LeaveProcessor
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly LeaveAccrualService $accruals,
        private readonly LeavePeriodClosureService $closures,
    ) {}

    /** @return array{tenants:int, granted:int, accrued:int, errors:int} */
    public function processAccruals(CarbonImmutable $date): array
    {
        $totals = ['tenants' => 0, 'granted' => 0, 'accrued' => 0, 'errors' => 0];

        foreach ($this->tenantIds() as $tenantId) {
            $totals['tenants']++;
            try {
                $counts = $this->context->runAs($tenantId, fn () => $this->accruals->processForDate($date));
                $totals['granted'] += $counts['granted'];
                $totals['accrued'] += $counts['accrued'];
            } catch (Throwable $e) {
                $totals['errors']++;
                report($e);
            }
        }

        return $totals;
    }

    /** @return array{tenants:int, closed:int, carried:int, expired:int, errors:int} */
    public function processPeriods(CarbonImmutable $date): array
    {
        $totals = ['tenants' => 0, 'closed' => 0, 'carried' => 0, 'expired' => 0, 'errors' => 0];

        foreach ($this->tenantIds() as $tenantId) {
            $totals['tenants']++;
            try {
                $counts = $this->context->runAs($tenantId, fn () => $this->closures->processForDate($date));
                $totals['closed'] += $counts['closed'];
                $totals['carried'] += $counts['carried'];
                $totals['expired'] += $counts['expired'];
            } catch (Throwable $e) {
                $totals['errors']++;
                report($e);
            }
        }

        return $totals;
    }

    /** @return array<int, string> */
    private function tenantIds(): array
    {
        return $this->context->runAsPlatform(
            fn () => Tenant::query()->orderBy('id')->pluck('id')->all()
        );
    }
}
