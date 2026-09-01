<?php

namespace App\Modules\Attendance\Http\Resources;

use App\Modules\Attendance\Models\Holiday;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Holiday */
class HolidayResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'holiday_calendar_id' => $this->holiday_calendar_id,
            'name' => $this->name,
            'date' => $this->date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'type' => $this->type instanceof \BackedEnum ? $this->type->value : $this->type,
            'is_paid' => $this->is_paid,
        ];
    }
}
