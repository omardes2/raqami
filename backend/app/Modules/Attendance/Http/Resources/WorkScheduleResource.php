<?php

namespace App\Modules\Attendance\Http\Resources;

use App\Modules\Attendance\Models\WorkSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WorkSchedule */
class WorkScheduleResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'timezone' => $this->timezone,
            'status' => $this->status?->value,
            'description' => $this->description,
            'grace_minutes' => $this->grace_minutes,
            'break_minutes' => $this->break_minutes,
            'overtime_after_minutes' => $this->overtime_after_minutes,
            'days' => $this->whenLoaded('days', fn () => $this->days
                ->sortBy('weekday')
                ->values()
                ->map(fn ($d) => [
                    'weekday' => $d->weekday,
                    'is_working_day' => $d->is_working_day,
                    'start_time' => $d->start_time,
                    'end_time' => $d->end_time,
                    'break_minutes' => $d->break_minutes,
                    'grace_minutes' => $d->grace_minutes,
                ])),
            'assignments' => $this->whenLoaded('assignments', fn () => $this->assignments->map(fn ($a) => [
                'id' => $a->id,
                'scope_type' => $a->scope_type?->value,
                'scope_id' => $a->scope_id,
                'effective_from' => $a->effective_from?->toDateString(),
                'effective_until' => $a->effective_until?->toDateString(),
                'priority' => $a->priority,
            ])),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
