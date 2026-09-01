<?php

namespace App\Modules\Organization\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Employees\Models\Employee;
use App\Modules\Organization\Models\Department;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Department creation/updates with hierarchy integrity (no circular parents) and
 * tenant-safe manager assignment.
 */
class DepartmentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(array $data, mixed $actor = null): Department
    {
        return DB::transaction(function () use ($data, $actor) {
            $this->validateManager($data['manager_employee_id'] ?? null);
            // No cycle possible on create (the row does not exist yet), but the
            // parent must belong to the tenant.
            $this->validateParentExists($data['parent_department_id'] ?? null);

            $department = Department::create($data);

            $this->audit->log('department.created', [
                'actor' => $actor,
                'subject' => $department,
                'metadata' => ['name' => $department->name, 'code' => $department->code],
            ]);

            return $department;
        });
    }

    public function update(Department $department, array $data, mixed $actor = null): Department
    {
        return DB::transaction(function () use ($department, $data, $actor) {
            if (array_key_exists('parent_department_id', $data)) {
                $this->validateParent($department, $data['parent_department_id']);
            }
            if (array_key_exists('manager_employee_id', $data)) {
                $this->validateManager($data['manager_employee_id']);
            }

            $department->fill($data)->save();

            $this->audit->log('department.updated', [
                'actor' => $actor,
                'subject' => $department,
                'metadata' => ['fields' => array_keys($data)],
            ]);

            return $department;
        });
    }

    private function validateParentExists(?string $parentId): void
    {
        if ($parentId !== null && ! Department::query()->whereKey($parentId)->exists()) {
            throw ValidationException::withMessages([
                'parent_department_id' => ['The selected parent department is invalid.'],
            ]);
        }
    }

    private function validateParent(Department $department, ?string $parentId): void
    {
        if ($parentId === null) {
            return;
        }
        if ($parentId === $department->getKey()) {
            throw ValidationException::withMessages([
                'parent_department_id' => ['A department cannot be its own parent.'],
            ]);
        }

        $this->validateParentExists($parentId);

        // Walk up the prospective parent's ancestry; a cycle exists if we reach
        // the department being edited.
        $cursor = Department::query()->find($parentId);
        $guard = 0;
        while ($cursor !== null && $guard++ < 1000) {
            if ($cursor->getKey() === $department->getKey()) {
                throw ValidationException::withMessages([
                    'parent_department_id' => ['This would create a circular department hierarchy.'],
                ]);
            }
            $cursor = $cursor->parent_department_id
                ? Department::query()->find($cursor->parent_department_id)
                : null;
        }
    }

    private function validateManager(?string $managerId): void
    {
        if ($managerId !== null && ! Employee::query()->whereKey($managerId)->exists()) {
            throw ValidationException::withMessages([
                'manager_employee_id' => ['The selected manager is invalid for this tenant.'],
            ]);
        }
    }
}
