<?php

namespace App\Modules\Leave\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Leave\Models\LeaveSetting;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Manages the single per-tenant leave settings row. Created lazily with safe,
 * jurisdiction-neutral defaults; every change is audited. display_day_minutes is
 * display-only — canonical accounting stays integer minutes.
 */
class LeaveSettingsService
{
    /** Fields a tenant admin may update. */
    private const UPDATABLE = [
        'default_period_basis', 'leave_year_start_month', 'leave_year_start_day',
        'default_approval_flow', 'allow_withdrawal', 'allow_cancellation_request',
        'display_day_minutes',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /** The tenant's leave settings, creating defaults on first access. */
    public function current(): LeaveSetting
    {
        $settings = LeaveSetting::query()->firstOrCreate(
            ['tenant_id' => $this->context->tenantId()],
        );

        return $settings->wasRecentlyCreated ? $settings->fresh() : $settings;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(array $input, mixed $actor = null): LeaveSetting
    {
        return DB::transaction(function () use ($input, $actor) {
            $settings = LeaveSetting::query()
                ->where('tenant_id', $this->context->tenantId())
                ->lockForUpdate()
                ->firstOrCreate(['tenant_id' => $this->context->tenantId()]);

            $changes = array_intersect_key($input, array_flip(self::UPDATABLE));
            $settings->fill($changes)->save();

            $this->audit->log('leave.settings_updated', [
                'actor' => $actor,
                'subject' => $settings,
                'metadata' => ['changed' => array_keys($changes)],
            ]);

            return $settings->fresh();
        });
    }
}
