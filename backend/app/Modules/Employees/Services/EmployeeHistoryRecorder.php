<?php

namespace App\Modules\Employees\Services;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeHistoryEvent;
use Illuminate\Support\Facades\Auth;

/**
 * Records the HR/business timeline for an employee. This is DISTINCT from the
 * security Audit Log: history is the employee's organizational story, audit is
 * security/activity logging. Never stores secrets.
 */
class EmployeeHistoryRecorder
{
    public function record(Employee $employee, string $eventType, array $metadata = [], ?string $effectiveDate = null): EmployeeHistoryEvent
    {
        return EmployeeHistoryEvent::create([
            'employee_id' => $employee->getKey(),
            'event_type' => $eventType,
            'effective_date' => $effectiveDate ?? now()->toDateString(),
            'actor_user_id' => Auth::id(),
            'metadata' => $metadata,
        ]);
    }
}
