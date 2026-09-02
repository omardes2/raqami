<?php

namespace App\Modules\Attendance\Http\Resources;

use App\Modules\Attendance\Models\HolidayCalendar;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin HolidayCalendar */
class HolidayCalendarResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'holidays' => $this->whenLoaded('holidays', fn () => HolidayResource::collection($this->holidays)),
            'assignments' => $this->whenLoaded('assignments', fn () => $this->assignments->map(fn ($a) => [
                'id' => $a->id,
                'scope_type' => $a->scope_type,
                'scope_id' => $a->scope_id,
                'effective_from' => $a->effective_from?->toDateString(),
                'effective_until' => $a->effective_until?->toDateString(),
            ])),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
