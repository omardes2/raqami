<?php

namespace App\Modules\Attendance\Support;

use App\Modules\Attendance\Enums\AttendanceStatus;

/**
 * The SERVER-computed numeric result of an attendance day. Every field is
 * derived from schedule snapshots + punch instants by AttendanceCalculator; the
 * client never supplies any of these.
 */
final class AttendanceComputation
{
    public function __construct(
        public readonly int $workedMinutes,
        public readonly int $breakMinutes,
        public readonly int $lateMinutes,
        public readonly int $earlyLeaveMinutes,
        public readonly int $overtimeMinutes,
        public readonly AttendanceStatus $status,
    ) {}
}
