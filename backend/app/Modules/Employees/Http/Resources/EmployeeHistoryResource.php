<?php

namespace App\Modules\Employees\Http\Resources;

use App\Modules\Employees\Models\EmployeeHistoryEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmployeeHistoryEvent */
class EmployeeHistoryResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_type' => $this->event_type,
            'effective_date' => $this->effective_date?->toDateString(),
            'actor_user_id' => $this->actor_user_id,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
        ];
    }
}
