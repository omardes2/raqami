<?php

namespace App\Modules\Tasks\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Compact task row for list/board/My-Tasks (no comments/activity). */
class TaskListResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'project_id' => $this->project_id,
            'parent_task_id' => $this->parent_task_id,
            'status_id' => $this->status_id,
            'status_category' => $this->whenLoaded('status', fn () => $this->status?->category?->value),
            'priority' => $this->priority?->value,
            'scope_type' => $this->scope_type?->value,
            'scope_id' => $this->scope_id,
            'due_type' => $this->due_type?->value,
            'due_on' => $this->due_on?->toDateString(),
            'due_at' => $this->due_at?->toIso8601String(),
            'due_timezone' => $this->due_timezone,
            'is_overdue' => $this->isOverdue(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'estimated_minutes' => $this->estimated_minutes,
            'board_rank' => $this->board_rank,
            'version' => $this->version,
            'assignees' => TaskAssigneeResource::collection($this->whenLoaded('assignees')),
        ];
    }
}
