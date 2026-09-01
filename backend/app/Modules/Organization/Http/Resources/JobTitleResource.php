<?php

namespace App\Modules\Organization\Http\Resources;

use App\Modules\Organization\Models\JobTitle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin JobTitle */
class JobTitleResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'code' => $this->code,
            'description' => $this->description,
            'level' => $this->level,
            'status' => $this->status,
            'employees_count' => $this->when(isset($this->employees_count), $this->employees_count),
        ];
    }
}
