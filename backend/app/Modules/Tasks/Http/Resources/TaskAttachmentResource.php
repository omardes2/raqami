<?php

namespace App\Modules\Tasks\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** storage_key is hidden on the model and never appears here. */
class TaskAttachmentResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'comment_id' => $this->comment_id,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'uploaded_by_user_id' => $this->uploaded_by_user_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
