<?php

namespace App\Modules\Tasks\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Full task detail. */
class TaskResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'parent_task_id' => $this->parent_task_id,
            'title' => $this->title,
            'description' => $this->description,
            'status_id' => $this->status_id,
            'status_category' => $this->whenLoaded('status', fn () => $this->status?->category?->value),
            'priority' => $this->priority?->value,
            'scope_type' => $this->scope_type?->value,
            'scope_id' => $this->scope_id,
            'due_type' => $this->due_type?->value,
            'due_on' => $this->due_on?->toDateString(),
            'due_at' => $this->due_at?->toIso8601String(),
            'due_timezone' => $this->due_timezone,
            'start_on' => $this->start_on?->toDateString(),
            'is_overdue' => $this->isOverdue(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'estimated_minutes' => $this->estimated_minutes,
            'board_rank' => $this->board_rank,
            'version' => $this->version,
            'created_by_user_id' => $this->created_by_user_id,
            'created_by_employee_id' => $this->created_by_employee_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'assignees' => TaskAssigneeResource::collection($this->whenLoaded('assignees')),
            'checklist_items' => TaskChecklistItemResource::collection($this->whenLoaded('checklistItems')),
            'attachments' => TaskAttachmentResource::collection($this->whenLoaded('attachments')),
            'subtasks' => TaskListResource::collection($this->whenLoaded('subtasks')),
        ];
    }
}
