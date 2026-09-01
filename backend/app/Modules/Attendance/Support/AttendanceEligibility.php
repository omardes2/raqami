<?php

namespace App\Modules\Attendance\Support;

use App\Modules\Employees\Models\Employee;

/**
 * Who may record attendance. Only employees in an actively-working employment
 * status can punch — suspended, on-leave, terminated, and archived employees
 * cannot. This is a hard server rule, independent of any UI.
 */
final class AttendanceEligibility
{
    /** Employment statuses eligible to record attendance. */
    public const ELIGIBLE_STATUSES = ['active', 'onboarding', 'probation'];

    public static function isEligible(Employee $employee): bool
    {
        return in_array($employee->employment_status, self::ELIGIBLE_STATUSES, true);
    }
}
