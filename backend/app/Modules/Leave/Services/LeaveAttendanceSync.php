<?php

namespace App\Modules\Leave\Services;

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Services\AttendanceDayMaterializer;
use App\Modules\Attendance\Services\AttendanceSettingsService;
use App\Modules\Attendance\Support\AttendanceLock;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Keeps materialized attendance consistent after leave changes. On final
 * cancellation, the days a request freed are re-derived through the central
 * AttendanceDayMaterializer (never by writing statuses from the Leave module): a
 * stale materialized `on_leave` record whose leave no longer covers it is
 * removed, then the day is re-materialized to its correct state. Real punches are
 * never touched. Uses the shared attendance advisory key.
 */
class LeaveAttendanceSync
{
    public function __construct(
        private readonly AttendanceDayMaterializer $materializer,
        private readonly AttendanceSettingsService $settings,
        private readonly LeaveResolver $leave,
        private readonly TenantContext $context,
    ) {}

    /** Re-materialize the future (not-yet-elapsed) days a request affected. */
    public function rematerializeForRequest(LeaveRequest $request): void
    {
        $employee = $request->employee;
        if ($employee === null) {
            return;
        }

        $settings = $this->settings->current();
        $now = CarbonImmutable::now()->utc();
        $today = CarbonImmutable::now()->startOfDay();

        $dates = $request->days()
            ->whereDate('work_date', '>=', $today->toDateString())
            ->pluck('work_date')
            ->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())
            ->unique()
            ->values();

        foreach ($dates as $dateStr) {
            $local = CarbonImmutable::parse($dateStr)->startOfDay();

            // Drop a stale materialized on_leave record no longer backed by leave.
            DB::transaction(function () use ($employee, $dateStr) {
                AttendanceLock::forEmployee((string) $this->context->tenantId(), (string) $employee->getKey());

                $record = AttendanceRecord::query()
                    ->where('employee_id', $employee->getKey())
                    ->whereDate('work_date', $dateStr)
                    ->lockForUpdate()
                    ->first();

                if ($record !== null
                    && $record->is_materialized
                    && $record->status === AttendanceStatus::OnLeave) {
                    $leave = $this->leave->resolve($employee, CarbonImmutable::parse($dateStr));
                    if ($leave === null || ! $leave->hasCoverage()) {
                        $record->delete();
                    }
                }
            });

            // Re-derive the day to its correct current state (weekend/absent/skip).
            $this->materializer->materializeEmployee($employee, $local, $now, $settings);
        }
    }
}
