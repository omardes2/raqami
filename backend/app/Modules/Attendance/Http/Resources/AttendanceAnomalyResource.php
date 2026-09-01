<?php

namespace App\Modules\Attendance\Http\Resources;

use App\Modules\Attendance\Models\AttendanceAnomaly;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Anomaly representation. Metadata is neutral, descriptive detail (distances,
 * counts, thresholds) — never a fraud judgment. Raw GPS coordinates are not
 * included here.
 *
 * @mixin AttendanceAnomaly
 */
class AttendanceAnomalyResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'attendance_record_id' => $this->attendance_record_id,
            'attendance_session_id' => $this->attendance_session_id,
            'type' => $this->type?->value,
            'severity' => $this->severity,
            'detected_at' => $this->detected_at?->toISOString(),
            'status' => $this->status?->value,
            'metadata' => $this->metadata,
            'resolved_at' => $this->resolved_at?->toISOString(),
            'resolved_by_user_id' => $this->resolved_by_user_id,
            'resolution_note' => $this->resolution_note,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
