<?php

namespace App\Modules\Leave\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Support\ResolvesActingEmployee;
use App\Modules\Leave\Http\Requests\LeaveAttachmentRequest;
use App\Modules\Leave\Http\Requests\LeaveSubmitRequest;
use App\Modules\Leave\Http\Resources\LeaveAttachmentResource;
use App\Modules\Leave\Http\Resources\LeaveRequestResource;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveRequestAttachment;
use App\Modules\Leave\Services\LeaveAttachmentService;
use App\Modules\Leave\Services\LeaveCancellationService;
use App\Modules\Leave\Services\LeaveReportService;
use App\Modules\Leave\Services\LeaveRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Employee leave self-service. The acting employee is derived from the
 * authenticated, employee-linked user (auth + link is the gate — never a
 * permission, and never another employee's data).
 */
class LeaveController extends Controller
{
    use ResolvesActingEmployee;

    public function __construct(
        private readonly LeaveRequestService $requests,
        private readonly LeaveCancellationService $cancellations,
        private readonly LeaveAttachmentService $attachments,
        private readonly LeaveReportService $reports,
    ) {}

    public function myBalances(Request $request): JsonResponse
    {
        $employee = $this->requireActingEmployee($request->user());

        return response()->json(['data' => $this->reports->employeeBalances($employee)]);
    }

    public function myRequests(Request $request): JsonResponse
    {
        $employee = $this->requireActingEmployee($request->user());

        $query = LeaveRequest::query()
            ->where('employee_id', $employee->getKey())
            ->with('days')
            ->orderByDesc('starts_on');

        return LeaveRequestResource::collection(
            $query->paginate(min((int) $request->query('per_page', 20), 100))
        )->response();
    }

    public function show(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $employee = $this->requireActingEmployee($request->user());
        abort_unless($leaveRequest->employee_id === $employee->getKey(), 404);

        return (new LeaveRequestResource($leaveRequest->load(['days', 'approvals', 'attachments'])))->response();
    }

    public function preview(LeaveSubmitRequest $request): JsonResponse
    {
        $employee = $this->requireActingEmployee($request->user());
        $preview = $this->requests->preview($employee, $request->validated());

        return response()->json([
            'available_before' => $preview['available_before'],
            'available_after' => $preview['available_after'],
            'total_consumption_minutes' => $preview['computation']->totalConsumptionMinutes,
            'total_coverage_minutes' => $preview['computation']->totalCoverageMinutes,
            'days' => array_map(fn ($d) => $d->toRow(), $preview['computation']->days),
        ]);
    }

    public function store(LeaveSubmitRequest $request): JsonResponse
    {
        $employee = $this->requireActingEmployee($request->user());
        // Self-service submitters never hold the negative-balance override.
        $leave = $this->requests->submit($employee, $request->validated(), $request->user(), false);

        return (new LeaveRequestResource($leave->load(['days', 'approvals'])))->response()->setStatusCode(201);
    }

    public function withdraw(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $employee = $this->requireActingEmployee($request->user());
        abort_unless($leaveRequest->employee_id === $employee->getKey(), 404);

        $leave = $this->requests->withdraw($leaveRequest, $request->user(), $request->integer('expected_version') ?: null);

        return (new LeaveRequestResource($leave))->response();
    }

    public function requestCancellation(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $employee = $this->requireActingEmployee($request->user());
        abort_unless($leaveRequest->employee_id === $employee->getKey(), 404);

        $leave = $this->cancellations->request($leaveRequest, $request->user(), $request->integer('expected_version') ?: null);

        return (new LeaveRequestResource($leave))->response();
    }

    public function storeAttachment(LeaveAttachmentRequest $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $employee = $this->requireActingEmployee($request->user());
        abort_unless($leaveRequest->employee_id === $employee->getKey(), 404);

        $attachment = $this->attachments->store(
            $leaveRequest, $request->file('file'), $request->input('category'), $request->user()
        );

        return (new LeaveAttachmentResource($attachment))->response()->setStatusCode(201);
    }

    public function downloadAttachment(Request $request, LeaveRequest $leaveRequest, LeaveRequestAttachment $attachment)
    {
        $employee = $this->requireActingEmployee($request->user());
        abort_unless($leaveRequest->employee_id === $employee->getKey(), 404);
        abort_unless($attachment->leave_request_id === $leaveRequest->getKey(), 404);

        // The employee may always access attachments on their OWN request.
        return $this->attachments->download($attachment);
    }
}
