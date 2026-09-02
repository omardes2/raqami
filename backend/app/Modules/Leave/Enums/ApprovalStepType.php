<?php

namespace App\Modules\Leave\Enums;

/**
 * The source that resolves a step's approver. `hr_pool` is NOT a single user —
 * it is an RBAC set (holders of leave.approve at a scope covering the employee),
 * so no workflow-engine directory is invented.
 */
enum ApprovalStepType: string
{
    case DirectManager = 'direct_manager';
    case DepartmentManager = 'department_manager';
    case TeamLead = 'team_lead';
    case HrPool = 'hr_pool';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
