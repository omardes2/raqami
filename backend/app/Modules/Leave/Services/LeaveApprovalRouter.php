<?php

namespace App\Modules\Leave\Services;

use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Enums\ApprovalFlow;
use App\Modules\Leave\Enums\ApprovalPurpose;
use App\Modules\Leave\Enums\ApprovalStatus;
use App\Modules\Leave\Enums\ApprovalStepType;
use App\Modules\Leave\Models\LeavePolicy;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveRequestApproval;
use Illuminate\Database\Eloquent\Model;

/**
 * Builds the snapshotted approval workflow at submission (so a later transfer
 * never reroutes a pending request). The `manager` step resolves through the
 * fallback chain direct_manager → department_manager → HR pool, skipping any
 * approver that resolves to the requester (self-approval). Team Lead is never
 * automatic. HR-pool steps carry no user — any holder of leave.approve covering
 * the employee may act (authorized at the controller via EmployeeScopeResolver).
 */
class LeaveApprovalRouter
{
    public function __construct(private readonly LeaveApprovalService $approvals) {}

    /**
     * Materialize approval steps for a freshly-submitted request. When the flow
     * is `none`, the request is finalized (approved) immediately.
     */
    public function buildForSubmission(LeaveRequest $request, Employee $employee, LeavePolicy $policy, Model $actor): void
    {
        $flow = $policy->approval_flow instanceof ApprovalFlow ? $policy->approval_flow : ApprovalFlow::Manager;

        if ($flow === ApprovalFlow::None) {
            $this->approvals->finalizeApproval($request, $actor);

            return;
        }

        $employee->loadMissing(['manager', 'department.manager']);
        $requesterUserId = $employee->user_id ? (string) $employee->user_id : null;

        $steps = match ($flow) {
            ApprovalFlow::Manager => [$this->managerStep($employee, $requesterUserId)],
            ApprovalFlow::Hr => [$this->hrStep()],
            ApprovalFlow::ManagerThenHr => [$this->managerStep($employee, $requesterUserId), $this->hrStep()],
            default => [$this->hrStep()],
        };

        $order = 1;
        foreach ($steps as $step) {
            $this->createStep($request, $order++, ApprovalPurpose::Approval, $step);
        }
    }

    /** Build a single cancellation-approval step (manager chain), pending. */
    public function buildCancellationStep(LeaveRequest $request, Employee $employee): LeaveRequestApproval
    {
        $employee->loadMissing(['manager', 'department.manager']);
        $requesterUserId = $employee->user_id ? (string) $employee->user_id : null;

        return $this->createStep($request, 1, ApprovalPurpose::Cancellation, $this->managerStep($employee, $requesterUserId));
    }

    /** Cancel all pending ORIGINAL-approval steps (used on withdrawal). */
    public function cancelOpenSteps(LeaveRequest $request): void
    {
        $request->approvals()
            ->where('purpose', ApprovalPurpose::Approval->value)
            ->where('status', ApprovalStatus::Pending->value)
            ->update(['status' => ApprovalStatus::Cancelled->value]);
    }

    /**
     * Resolve the manager step via the fallback chain, skipping a self-approver.
     *
     * @return array{approver_type:ApprovalStepType, approver_user_id:?string}
     */
    private function managerStep(Employee $employee, ?string $requesterUserId): array
    {
        $directUserId = $employee->manager?->user_id ? (string) $employee->manager->user_id : null;
        if ($directUserId !== null && $directUserId !== $requesterUserId) {
            return ['approver_type' => ApprovalStepType::DirectManager, 'approver_user_id' => $directUserId];
        }

        $deptUserId = $employee->department?->manager?->user_id ? (string) $employee->department->manager->user_id : null;
        if ($deptUserId !== null && $deptUserId !== $requesterUserId) {
            return ['approver_type' => ApprovalStepType::DepartmentManager, 'approver_user_id' => $deptUserId];
        }

        // No valid manager (unset, unlinked, or self) → fall back to the HR pool.
        return ['approver_type' => ApprovalStepType::HrPool, 'approver_user_id' => null];
    }

    /** @return array{approver_type:ApprovalStepType, approver_user_id:?string} */
    private function hrStep(): array
    {
        return ['approver_type' => ApprovalStepType::HrPool, 'approver_user_id' => null];
    }

    /**
     * @param  array{approver_type:ApprovalStepType, approver_user_id:?string}  $step
     */
    private function createStep(LeaveRequest $request, int $order, ApprovalPurpose $purpose, array $step): LeaveRequestApproval
    {
        return $request->approvals()->create([
            'step_order' => $order,
            'purpose' => $purpose,
            'approver_type' => $step['approver_type'],
            'approver_user_id' => $step['approver_user_id'],
            'required_permission' => 'leave.approve',
            'scope_type' => 'company',
            'scope_id' => null,
            'status' => ApprovalStatus::Pending,
        ]);
    }
}
