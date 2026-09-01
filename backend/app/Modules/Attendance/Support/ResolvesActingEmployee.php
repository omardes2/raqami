<?php

namespace App\Modules\Attendance\Support;

use App\Modules\Employees\Models\Employee;
use App\Modules\Identity\Models\User;

/**
 * Controller helper: map the authenticated user to their own Employee record for
 * self-service attendance. A user with no linked, eligible employee cannot punch
 * — returning a scope-safe error rather than acting on someone else's record.
 */
trait ResolvesActingEmployee
{
    protected function actingEmployee(User $user): ?Employee
    {
        return Employee::query()->where('user_id', $user->getKey())->first();
    }

    protected function requireActingEmployee(User $user): Employee
    {
        $employee = $this->actingEmployee($user);

        abort_if($employee === null, 403, 'Your account is not linked to an employee record.');

        return $employee;
    }
}
