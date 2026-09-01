<?php

namespace App\Modules\Employees\Support;

use App\Modules\Employees\Models\Employee;
use Illuminate\Http\Request;

/**
 * Controller helper: enforce organizational-scope access to a specific employee.
 * Returns a scope-safe 404 (not 403) so out-of-scope existence is not leaked.
 */
trait AuthorizesEmployeeScope
{
    protected function authorizeEmployeeScope(Request $request, Employee $employee, string $permission): void
    {
        $resolver = app(EmployeeScopeResolver::class);
        if (! $resolver->canAccess($request->user(), $employee, $permission)) {
            abort(404);
        }
    }
}
