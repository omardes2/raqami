<?php

namespace App\Modules\Leave\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Leave\Models\LeavePolicy;
use App\Modules\Leave\Models\LeavePolicyAssignment;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Assign / unassign a leave policy to an organizational scope. Validates that a
 * named branch/department/team scope target belongs to the SAME tenant (a plain
 * FK cannot prove it — cross-tenant integrity, SECURITY). Every change audited.
 */
class LeavePolicyAssignmentService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function assign(LeavePolicy $policy, array $input, mixed $actor = null): LeavePolicyAssignment
    {
        $scopeType = $input['scope_type'];
        $scopeId = $input['scope_id'] ?? null;

        $this->assertScopeTarget($scopeType, $scopeId);

        return DB::transaction(function () use ($policy, $input, $scopeType, $scopeId, $actor) {
            $assignment = LeavePolicyAssignment::query()->create([
                'leave_policy_id' => $policy->getKey(),
                'leave_type_id' => $policy->leave_type_id,
                'scope_type' => $scopeType,
                'scope_id' => $scopeType === 'company' ? null : $scopeId,
                'effective_from' => $input['effective_from'],
                'effective_until' => $input['effective_until'] ?? null,
                'priority' => (int) ($input['priority'] ?? 0),
            ]);

            $this->audit->log('leave.policy_assigned', [
                'actor' => $actor,
                'subject' => $assignment,
                'metadata' => [
                    'leave_policy_id' => (string) $policy->getKey(),
                    'scope_type' => $scopeType,
                    'scope_id' => $scopeId,
                ],
            ]);

            return $assignment;
        });
    }

    public function unassign(LeavePolicyAssignment $assignment, mixed $actor = null): void
    {
        DB::transaction(function () use ($assignment, $actor) {
            $this->audit->log('leave.policy_unassigned', [
                'actor' => $actor,
                'subject' => $assignment,
                'metadata' => ['leave_policy_id' => (string) $assignment->leave_policy_id],
            ]);

            $assignment->delete();
        });
    }

    /** A branch/department/team/employee scope target must exist within THIS tenant. */
    private function assertScopeTarget(string $scopeType, ?string $scopeId): void
    {
        if ($scopeType === 'company') {
            return;
        }

        if ($scopeId === null) {
            throw ValidationException::withMessages(['scope_id' => [__('leave.scope_target_invalid')]]);
        }

        $table = match ($scopeType) {
            'branch' => 'branches',
            'department' => 'departments',
            'team' => 'teams',
            'employee' => 'employees',
            default => null,
        };

        if ($table === null) {
            throw ValidationException::withMessages(['scope_type' => [__('leave.scope_target_invalid')]]);
        }

        // Same-tenant existence check (RLS already scopes, but be explicit).
        $exists = DB::table($table)
            ->where('id', $scopeId)
            ->where('tenant_id', $this->context->tenantId())
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages(['scope_id' => [__('leave.scope_target_invalid')]]);
        }
    }
}
