<?php

namespace App\Modules\Attendance\Http\Resources;

use App\Modules\Attendance\Models\AttendanceException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AttendanceException */
class AttendanceExceptionResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'type' => $this->type?->value,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_until' => $this->effective_until?->toDateString(),
            'attendance_mode' => $this->attendance_mode?->value,
            'alternate_schedule_id' => $this->alternate_schedule_id,
            'alternate_location_id' => $this->alternate_location_id,
            'reason' => $this->reason,
            'status' => $this->status,
            'approved_by_user_id' => $this->approved_by_user_id,
            'created_by_user_id' => $this->created_by_user_id,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
