<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Runs daily attendance materialization across every tenant. Tenant ids are
 * discovered via the audited platform read-only context, then each tenant is
 * processed inside ITS OWN tenant context so RLS holds and no cross-tenant data
 * is ever touched. A failure for one tenant is isolated and does not abort the
 * rest. Idempotent (delegates to AttendanceDayMaterializer). No cron is wired
 * here — this is the callable foundation a scheduler/command invokes.
 */
class AttendanceDailyProcessor
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AttendanceDayMaterializer $materializer,
        private readonly AttendanceAnomalyService $anomalies,
    ) {}

    /**
     * @return array{tenants:int, absent:int, weekend:int, holiday:int, incomplete:int, skipped:int, anomalies:int, errors:int}
     */
    public function process(CarbonImmutable $localDate, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $totals = ['tenants' => 0, 'absent' => 0, 'weekend' => 0, 'holiday' => 0, 'on_leave' => 0, 'incomplete' => 0, 'skipped' => 0, 'anomalies' => 0, 'errors' => 0];

        $tenantIds = $this->context->runAsPlatform(
            fn () => Tenant::query()->orderBy('id')->pluck('id')->all()
        );

        foreach ($tenantIds as $tenantId) {
            $totals['tenants']++;

            try {
                $counts = $this->context->runAs($tenantId, function () use ($localDate, $now) {
                    // Materialize derived state first, then detect anomalies over it.
                    $materialized = $this->materializer->materialize($localDate, $now);
                    $materialized['anomalies'] = $this->anomalies->detect($localDate, $now);

                    return $materialized;
                });

                foreach (['absent', 'weekend', 'holiday', 'on_leave', 'incomplete', 'skipped', 'anomalies'] as $key) {
                    $totals[$key] += $counts[$key];
                }
            } catch (Throwable $e) {
                $totals['errors']++;
                report($e);
            }
        }

        return $totals;
    }
}
