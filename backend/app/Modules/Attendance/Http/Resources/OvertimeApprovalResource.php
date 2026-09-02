<?php

namespace App\Modules\Attendance\Http\Resources;

use App\Modules\Attendance\Models\OvertimeApproval;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OvertimeApproval */
class OvertimeApprovalResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'attendance_record_id' => $this->attendance_record_id,
            'employee_id' => $this->employee_id,
            'work_date' => $this->work_date?->toDateString(),
            'calculated_minutes' => $this->calculated_minutes,
            'approved_minutes' => $this->approved_minutes,
            'status' => $this->status?->value,
            'reviewed_by_user_id' => $this->reviewed_by_user_id,
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
