<?php

namespace App\Modules\Tasks\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectMembershipResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'employee_id' => $this->employee_id,
            'role' => $this->role?->value,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
