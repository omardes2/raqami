<?php

namespace App\Modules\Leave\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Support\EmployeeScopeResolver;
use App\Modules\Leave\Http\Requests\LeaveDecisionRequest;
use App\Modules\Leave\Http\Requests\LeaveDirectCancelRequest;
use App\Modules\Leave\Http\Resources\LeaveRequestResource;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveRequestAttachment;
use App\Modules\Leave\Services\LeaveApprovalService;
use App\Modules\Leave\Services\LeaveAttachmentService;
use App\Modules\Leave\Services\LeaveCancellationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Management of employees' leave requests: list/show (organizational scope), and
 * the approval / rejection / cancellation decisions. Every per-employee action is
 * scope-checked via EmployeeScopeResolver with a scope-safe 404. Self-approval is
 * blocked in the service layer regardless of role.
 */
class LeaveRequestController extends Controller
{
    public function __construct(
        private readonly LeaveApprovalService $approvals,
        private readonly LeaveCancellationService $cancellations,
        private readonly LeaveAttachmentService $attachments,
        private readonly EmployeeScopeResolver $scope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = LeaveRequest::query()
            ->whereHas('employee', fn ($q) => $this->scope->applyScope($q, $request->user(), 'leave.view'))
            ->with(['days', 'policy', 'leaveType', 'attachments'])
            ->orderByDesc('starts_on');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->query('employee_id'));
        }

        return LeaveRequestResource::collection(
            $query->paginate(min((int) $request->query('per_page', 20), 100))
        )->response();
    }

    public function show(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorizeLeave($request, $leaveRequest, 'leave.view');

        return (new LeaveRequestResource($leaveRequest->load(['days', 'approvals'])))->response();
    }

    public function approve(LeaveDecisionRequest $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorizeLeave($request, $leaveRequest, 'leave.approve');

        $leave = $this->approvals->approve(
            $leaveRequest, $request->user(), $request->input('note'), $request->integer('expected_version') ?: null
        );

        return (new LeaveRequestResource($leave->load(['days', 'approvals'])))->response();
    }

    public function reject(LeaveDecisionRequest $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorizeLeave($request, $leaveRequest, 'leave.approve');

        $leave = $this->approvals->reject(
            $leaveRequest, $request->user(), $request->input('note'), $request->integer('expected_version') ?: null
        );

        return (new LeaveRequestResource($leave->load('approvals')))->response();
    }

    public function cancel(LeaveDirectCancelRequest $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorizeLeave($request, $leaveRequest, 'leave.manage');

        $leave = $this->cancellations->directCancel($leaveRequest, $request->user(), (string) $request->input('reason'));

        return (new LeaveRequestResource($leave))->response();
    }

    public function approveCancellation(LeaveDecisionRequest $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorizeLeave($request, $leaveRequest, 'leave.approve');

        $leave = $this->cancellations->approve($leaveRequest, $request->user(), $request->input('note'));

        return (new LeaveRequestResource($leave))->response();
    }

    public function rejectCancellation(LeaveDecisionRequest $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorizeLeave($request, $leaveRequest, 'leave.approve');

        $leave = $this->cancellations->reject($leaveRequest, $request->user(), $request->input('note'));

        return (new LeaveRequestResource($leave))->response();
    }

    public function downloadAttachment(Request $request, LeaveRequest $leaveRequest, LeaveRequestAttachment $attachment)
    {
        $employee = $this->authorizeLeave($request, $leaveRequest, 'leave.view');
        abort_unless($attachment->leave_request_id === $leaveRequest->getKey(), 404);

        // Sensitive (e.g. medical) attachments require the distinct permission —
        // a leave viewer/approver does NOT automatically gain access.
        if ($attachment->category === 'medical_certificate'
            && ! $this->scope->canAccess($request->user(), $employee, 'leave.attachments.view_sensitive')) {
            abort(403, __('leave.attachment_forbidden'));
        }

        return $this->attachments->download($attachment);
    }

    /** Scope-safe authorization for a leave request's employee. Returns the employee. */
    private function authorizeLeave(Request $request, LeaveRequest $leaveRequest, string $permission): Employee
    {
        $employee = $leaveRequest->employee ?? Employee::query()->find($leaveRequest->employee_id);
        abort_if($employee === null || ! $this->scope->canAccess($request->user(), $employee, $permission), 404);

        return $employee;
    }
}
