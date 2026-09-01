<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\AttendanceSetting;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Manages the single per-tenant attendance policy row. Settings are created
 * lazily with safe, non-committal defaults (no global legal/commercial
 * assumptions) and every change is audited.
 */
class AttendanceSettingsService
{
    /** Fields a tenant admin may update. */
    private const UPDATABLE = [
        'default_timezone', 'default_grace_minutes', 'geofence_required', 'require_gps',
        'min_gps_accuracy_meters', 'allow_early_check_in', 'early_check_in_window_minutes',
        'allow_late_check_in', 'overtime_tracking_enabled', 'overtime_after_minutes',
        'attendance_correction_enabled', 'allow_employee_correction_request',
        'allow_unscheduled_work',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /** The tenant's attendance settings, creating defaults on first access. */
    public function current(): AttendanceSetting
    {
        return AttendanceSetting::query()->firstOrCreate(
            ['tenant_id' => $this->context->tenantId()],
        );
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(array $input, mixed $actor = null): AttendanceSetting
    {
        return DB::transaction(function () use ($input, $actor) {
            $settings = AttendanceSetting::query()
                ->where('tenant_id', $this->context->tenantId())
                ->lockForUpdate()
                ->firstOrCreate(['tenant_id' => $this->context->tenantId()]);

            $changes = array_intersect_key($input, array_flip(self::UPDATABLE));
            $settings->fill($changes)->save();

            $this->audit->log('attendance.settings_updated', [
                'actor' => $actor,
                'subject' => $settings,
                'metadata' => ['changed' => array_keys($changes)],
            ]);

            return $settings->refresh();
        });
    }
}
