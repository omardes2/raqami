<?php

namespace App\Modules\Employees\Http\Resources;

use App\Modules\Authorization\Services\AccessService;
use App\Modules\Employees\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full employee representation. SENSITIVE fields are only included when the
 * requesting user holds employees.view_sensitive — sensitive data is never
 * exposed through generic serialization (CLAUDE.md rule 5).
 *
 * @mixin Employee
 */
class EmployeeResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $canSensitive = $request->user() !== null
            && app(AccessService::class)->hasAtAnyScope($request->user(), 'employees.view_sensitive');

        $data = [
            'id' => $this->id,
            'employee_number' => $this->employee_number,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'display_name' => $this->display_name,
            'full_name' => $this->fullName(),

            'branch_id' => $this->branch_id,
            'department_id' => $this->department_id,
            'job_title_id' => $this->job_title_id,
            'direct_manager_employee_id' => $this->direct_manager_employee_id,

            'employment_status' => $this->employment_status,
            'employment_type' => $this->employment_type,
            'hire_date' => $this->hire_date?->toDateString(),
            'probation_end_date' => $this->probation_end_date?->toDateString(),
            'termination_date' => $this->termination_date?->toDateString(),

            'user_id' => $this->user_id,
            'work_email' => $this->work_email,
            'work_phone' => $this->work_phone,
            'gender' => $this->gender,
            'country_code' => $this->country_code,
            'city' => $this->city,
            'status' => $this->status,

            // Relations (only when eager-loaded)
            'branch' => $this->whenLoaded('branch', fn () => ['id' => $this->branch?->id, 'name' => $this->branch?->name]),
            'department' => $this->whenLoaded('department', fn () => ['id' => $this->department?->id, 'name' => $this->department?->name]),
            'job_title' => $this->whenLoaded('jobTitle', fn () => ['id' => $this->jobTitle?->id, 'title' => $this->jobTitle?->title]),
            'manager' => $this->whenLoaded('manager', fn () => $this->manager ? ['id' => $this->manager->id, 'full_name' => $this->manager->fullName()] : null),
            'teams' => $this->whenLoaded('teams', fn () => $this->teams->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])),
            'has_user_account' => $this->user_id !== null,
        ];

        if ($canSensitive) {
            $data['sensitive'] = [
                'personal_email' => $this->personal_email,
                'mobile_phone' => $this->mobile_phone,
                'date_of_birth' => $this->date_of_birth?->toDateString(),
                'nationality' => $this->nationality,
                'address_line' => $this->address_line,
                'notes' => $this->notes,
            ];
        }

        return $data;
    }
}
