<?php

namespace App\Modules\Leave\Http\Resources;

use App\Modules\Leave\Models\LeaveType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LeaveType */
class LeaveTypeResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category?->value,
            'status' => $this->status?->value,
            'paid_classification' => $this->paid_classification,
            'requires_attachment' => (bool) $this->requires_attachment,
            'attachment_required_after_minutes' => $this->attachment_required_after_minutes,
            'allow_half_day' => (bool) $this->allow_half_day,
            'allow_hourly' => (bool) $this->allow_hourly,
            'color' => $this->color,
        ];
    }
}
