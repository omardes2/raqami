<?php

namespace App\Modules\Tasks\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskAssigneeResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'employee_id' => $this->employee_id,
            'is_primary' => (bool) $this->is_primary,
            'assigned_at' => $this->assigned_at?->toIso8601String(),
        ];
    }
}
