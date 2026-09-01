<?php

namespace App\Modules\Employees\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Employees\Models\Employee;
use App\Modules\Organization\Models\Branch;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\JobTitle;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create / update / archive employees. Organizational placement changes on an
 * existing employee go through EmployeeTransferService; this service handles the
 * profile/employment fields and initial creation. All multi-step work runs in a
 * transaction and records both an HR history event and a security audit entry.
 */
class EmployeeService
{
    /** Fields updatable via update() — NOT the org placement FKs. */
    private const UPDATABLE = [
        'first_name', 'middle_name', 'last_name', 'display_name',
        'employment_type', 'hire_date', 'probation_end_date',
        'work_email', 'personal_email', 'work_phone', 'mobile_phone',
        'date_of_birth', 'gender', 'nationality', 'country_code', 'address_line', 'city',
        'notes',
    ];

    public function __construct(
        private readonly EmployeeNumberGenerator $numbers,
        private readonly EmployeeHistoryRecorder $history,
        private readonly AuditLogger $audit,
    ) {}

    public function create(array $data, mixed $actor = null): Employee
    {
        return DB::transaction(function () use ($data, $actor) {
            $this->assertReferencesInTenant($data);

            $data['employee_number'] = $data['employee_number']
                ?? $this->numbers->next();

            $employee = Employee::create($data);

            $this->history->record($employee, 'hired', [
                'employee_number' => $employee->employee_number,
                'branch_id' => $employee->branch_id,
                'department_id' => $employee->department_id,
                'job_title_id' => $employee->job_title_id,
            ], $employee->hire_date?->toDateString());

            $this->audit->log('employee.created', [
                'actor' => $actor,
                'subject' => $employee,
                'metadata' => ['employee_number' => $employee->employee_number],
            ]);

            return $employee;
        });
    }

    public function update(Employee $employee, array $data, mixed $actor = null): Employee
    {
        return DB::transaction(function () use ($employee, $data, $actor) {
            $changes = array_intersect_key($data, array_flip(self::UPDATABLE));
            $employee->fill($changes)->save();

            $this->audit->log('employee.updated', [
                'actor' => $actor,
                'subject' => $employee,
                'metadata' => ['fields' => array_keys($changes)],
            ]);

            return $employee;
        });
    }

    public function changeStatus(Employee $employee, string $status, ?string $reason = null, mixed $actor = null): Employee
    {
        return DB::transaction(function () use ($employee, $status, $reason, $actor) {
            $from = $employee->employment_status;
            $employee->employment_status = $status;
            if ($status === 'terminated') {
                $employee->termination_date = now()->toDateString();
                $employee->termination_reason = $reason;
            }
            $employee->save();

            $this->history->record($employee, 'status_changed', [
                'from' => $from,
                'to' => $status,
                'reason' => $reason,
            ]);
            $this->audit->log('employee.status_changed', [
                'actor' => $actor,
                'subject' => $employee,
                'metadata' => ['from' => $from, 'to' => $status],
            ]);

            return $employee;
        });
    }

    /** Archive (soft) — never a hard delete once business history exists. */
    public function archive(Employee $employee, mixed $actor = null): Employee
    {
        return DB::transaction(function () use ($employee, $actor) {
            $employee->status = 'archived';
            if ($employee->employment_status !== 'terminated') {
                $employee->employment_status = 'archived';
            }
            $employee->save();
            $employee->delete(); // soft delete

            $this->history->record($employee, 'terminated', ['archived' => true]);
            $this->audit->log('employee.archived', [
                'actor' => $actor,
                'subject' => $employee,
            ]);

            return $employee;
        });
    }

    /** Reject org references that do not resolve within the active tenant. */
    private function assertReferencesInTenant(array $data): void
    {
        $errors = [];

        if (! empty($data['branch_id']) && ! Branch::query()->whereKey($data['branch_id'])->exists()) {
            $errors['branch_id'] = ['The selected branch is invalid.'];
        }
        if (! empty($data['department_id']) && ! Department::query()->whereKey($data['department_id'])->exists()) {
            $errors['department_id'] = ['The selected department is invalid.'];
        }
        if (! empty($data['job_title_id']) && ! JobTitle::query()->whereKey($data['job_title_id'])->exists()) {
            $errors['job_title_id'] = ['The selected job title is invalid.'];
        }
        if (! empty($data['direct_manager_employee_id']) && ! Employee::query()->whereKey($data['direct_manager_employee_id'])->exists()) {
            $errors['direct_manager_employee_id'] = ['The selected manager is invalid.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
