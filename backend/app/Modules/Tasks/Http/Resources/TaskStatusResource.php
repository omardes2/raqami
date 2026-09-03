<?php

namespace App\Modules\Tasks\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskStatusResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'category' => $this->category?->value,
            'color' => $this->color,
            'sort_order' => $this->sort_order,
            'is_default' => (bool) $this->is_default,
            'active' => (bool) $this->active,
        ];
    }
}
