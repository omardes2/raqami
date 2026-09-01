<?php

namespace App\Modules\Organization\Http\Resources;

use App\Modules\Organization\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Team */
class TeamResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'branch_id' => $this->branch_id,
            'department_id' => $this->department_id,
            'team_lead_employee_id' => $this->team_lead_employee_id,
            'status' => $this->status,
            'members_count' => $this->when(isset($this->members_count), $this->members_count),
        ];
    }
}
