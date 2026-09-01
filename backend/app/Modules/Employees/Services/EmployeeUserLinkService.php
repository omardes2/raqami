<?php

namespace App\Modules\Employees\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Employees\Models\Employee;
use App\Modules\Identity\Models\TenantMembership;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Controlled linking of an Employee (HR record) to a User (auth identity).
 * Linking is always optional — most employees have no login. Enforces:
 *  - the user is a member of the ACTIVE tenant (blocks foreign-tenant users),
 *  - a user is linked to at most one non-archived employee in the tenant.
 */
class EmployeeUserLinkService
{
    public function __construct(
        private readonly EmployeeHistoryRecorder $history,
        private readonly AuditLogger $audit,
    ) {}

    public function link(Employee $employee, string $userId, mixed $actor = null): Employee
    {
        return DB::transaction(function () use ($employee, $userId, $actor) {
            // Membership lookup is RLS/tenant-scoped: a user from another tenant
            // has no membership here, so this rejects cross-tenant linking.
            $isMember = TenantMembership::query()->where('user_id', $userId)->exists();
            if (! $isMember) {
                throw ValidationException::withMessages([
                    'user_id' => ['The user must be a member of this company.'],
                ]);
            }

            $alreadyLinked = Employee::query()
                ->where('user_id', $userId)
                ->whereKeyNot($employee->getKey())
                ->exists();
            if ($alreadyLinked) {
                throw ValidationException::withMessages([
                    'user_id' => ['This user is already linked to another employee.'],
                ]);
            }

            $employee->user_id = $userId;
            $employee->save();

            $this->history->record($employee, 'user_linked', ['user_id' => $userId]);
            $this->audit->log('employee.user_linked', [
                'actor' => $actor,
                'subject' => $employee,
                'metadata' => ['user_id' => $userId],
            ]);

            return $employee;
        });
    }

    public function unlink(Employee $employee, mixed $actor = null): Employee
    {
        return DB::transaction(function () use ($employee, $actor) {
            $previous = $employee->user_id;
            if ($previous === null) {
                return $employee;
            }

            $employee->user_id = null;
            $employee->save();

            $this->history->record($employee, 'user_unlinked', ['user_id' => $previous]);
            $this->audit->log('employee.user_unlinked', [
                'actor' => $actor,
                'subject' => $employee,
                'metadata' => ['user_id' => $previous],
            ]);

            return $employee;
        });
    }
}
