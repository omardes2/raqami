<?php

namespace App\Modules\Tasks\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskChecklistItemResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'text' => $this->text,
            'is_completed' => (bool) $this->is_completed,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'sort_order' => $this->sort_order,
        ];
    }
}
