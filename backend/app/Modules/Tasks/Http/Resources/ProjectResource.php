<?php

namespace App\Modules\Tasks\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'status' => $this->status?->value,
            'visibility' => $this->visibility?->value,
            'scope_type' => $this->scope_type?->value,
            'scope_id' => $this->scope_id,
            'owner_employee_id' => $this->owner_employee_id,
            'start_on' => $this->start_on?->toDateString(),
            'due_on' => $this->due_on?->toDateString(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'is_archived' => $this->isArchived(),
            'version' => $this->version,
            'progress' => $this->when(isset($this->progress), fn () => $this->progress),
            'members' => ProjectMembershipResource::collection($this->whenLoaded('memberships')),
        ];
    }
}
