<?php

namespace App\Modules\Employees\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Compact employee row for list endpoints — never includes sensitive fields. */
class EmployeeListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_number' => $this->employee_number,
            'full_name' => $this->fullName(),
            'work_email' => $this->work_email,
            'employment_status' => $this->employment_status,
            'employment_type' => $this->employment_type,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'department' => $this->whenLoaded('department', fn () => $this->department?->name),
            'job_title' => $this->whenLoaded('jobTitle', fn () => $this->jobTitle?->title),
            'manager' => $this->whenLoaded('manager', fn () => $this->manager?->fullName()),
            'has_user_account' => $this->user_id !== null,
        ];
    }
}
