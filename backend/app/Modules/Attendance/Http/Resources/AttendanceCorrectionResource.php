<?php

namespace App\Modules\Attendance\Http\Resources;

use App\Modules\Attendance\Models\AttendanceCorrection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AttendanceCorrection */
class AttendanceCorrectionResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'attendance_record_id' => $this->attendance_record_id,
            'attendance_session_id' => $this->attendance_session_id,
            'employee_id' => $this->employee_id,
            'requested_by_user_id' => $this->requested_by_user_id,
            'requested_check_in_at' => $this->requested_check_in_at?->toISOString(),
            'requested_check_out_at' => $this->requested_check_out_at?->toISOString(),
            'reason' => $this->reason,
            'status' => $this->status?->value,
            'reviewed_by_user_id' => $this->reviewed_by_user_id,
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'rejection_reason' => $this->rejection_reason,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
