<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Payroll\Models\PayrollSetting;
use App\Modules\Payroll\Support\PayrollLock;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Per-tenant payroll settings. One row per tenant; created idempotently at
 * onboarding and by a backfill command (race-safe via advisory lock).
 */
class PayrollSettingsService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    /** Race-safe create-or-fetch of the tenant's single settings row. */
    public function getOrCreate(): PayrollSetting
    {
        return DB::transaction(function () {
            $tenantId = $this->context->tenantId();
            PayrollLock::forSettings((string) $tenantId);

            $existing = PayrollSetting::query()->where('tenant_id', $tenantId)->first();
            if ($existing !== null) {
                return $existing;
            }

            return PayrollSetting::query()->create([
                'payroll_timezone' => $this->context->tenant()?->timezone ?? 'UTC',
            ])->fresh();
        });
    }

    /**
     * @param  array{payroll_timezone?:string, overtime_pay_enabled?:bool, require_four_eyes?:bool, allow_self_payroll?:bool}  $data
     */
    public function update(User $actor, array $data): PayrollSetting
    {
        $this->getOrCreate();

        return DB::transaction(function () use ($actor, $data) {
            $settings = PayrollSetting::query()
                ->where('tenant_id', $this->context->tenantId())
                ->lockForUpdate()->firstOrFail();

            foreach (['payroll_timezone', 'overtime_pay_enabled', 'require_four_eyes', 'allow_self_payroll'] as $field) {
                if (array_key_exists($field, $data)) {
                    $settings->{$field} = $data[$field];
                }
            }
            $settings->version = (int) $settings->version + 1;
            $settings->save();

            $this->audit->log('payroll.settings_updated', [
                'actor' => $actor, 'subject' => $settings, 'metadata' => ['fields' => array_keys($data)],
            ]);

            return $settings->fresh();
        });
    }
}
