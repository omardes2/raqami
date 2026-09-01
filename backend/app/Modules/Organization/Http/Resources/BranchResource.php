<?php

namespace App\Modules\Organization\Http\Resources;

use App\Modules\Organization\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Branch */
class BranchResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'country_code' => $this->country_code,
            'city' => $this->city,
            'address_line' => $this->address_line,
            'timezone' => $this->timezone,
            'phone' => $this->phone,
            'email' => $this->email,
            'is_headquarters' => $this->is_headquarters,
            'status' => $this->status,
            'employees_count' => $this->when(isset($this->employees_count), $this->employees_count),
        ];
    }
}
