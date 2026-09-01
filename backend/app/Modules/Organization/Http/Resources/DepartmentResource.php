<?php

namespace App\Modules\Organization\Http\Resources;

use App\Modules\Organization\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Department */
class DepartmentResource extends JsonResource
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
            'parent_department_id' => $this->parent_department_id,
            'manager_employee_id' => $this->manager_employee_id,
            'status' => $this->status,
            'employees_count' => $this->when(isset($this->employees_count), $this->employees_count),
        ];
    }
}
