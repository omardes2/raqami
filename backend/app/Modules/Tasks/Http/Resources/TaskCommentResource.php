<?php

namespace App\Modules\Tasks\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskCommentResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $deleted = $this->deleted_at !== null;

        return [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'user_id' => $this->user_id,
            'employee_id' => $this->employee_id,
            'body' => $deleted ? null : $this->body,
            'is_deleted' => $deleted,
            'edited_at' => $this->edited_at?->toIso8601String(),
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601String(),
            'mentions' => $this->whenLoaded('mentions', fn () => $this->mentions->pluck('mentioned_user_id')),
        ];
    }
}
