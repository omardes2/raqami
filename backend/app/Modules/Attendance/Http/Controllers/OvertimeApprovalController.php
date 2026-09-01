<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Http\Requests\OvertimeReviewRequest;
use App\Modules\Attendance\Http\Resources\OvertimeApprovalResource;
use App\Modules\Attendance\Models\OvertimeApproval;
use App\Modules\Attendance\Services\OvertimeApprovalService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Support\EmployeeScopeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Overtime approval review. Scope-checked against the target employee. The
 * SERVER's raw calculated_minutes is never editable here; a reviewer only sets
 * approved_minutes. No self-approval, no over-approval without override.
 */
class OvertimeApprovalController extends Controller
{
    public function __construct(
        private readonly OvertimeApprovalService $overtime,
        private readonly EmployeeScopeResolver $scope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = OvertimeApproval::query()
            ->whereHas('employee', fn ($q) => $this->scope->applyScope($q, $request->user(), 'attendance.overtime.view'))
            ->orderByDesc('work_date');

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->query('employee_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return OvertimeApprovalResource::collection(
            $query->paginate(min((int) $request->query('per_page', 20), 100))
        )->response();
    }

    public function approve(OvertimeReviewRequest $request, OvertimeApproval $approval): JsonResponse
    {
        $this->authorizeApproval($request, $approval);

        $data = $request->validated();
        $approval = $this->overtime->approve(
            $approval,
            $request->user(),
            $data['approved_minutes'] ?? null,
            $data['notes'] ?? null,
            (bool) ($data['allow_override'] ?? false),
            $data['expected_record_version'] ?? null,
        );

        return (new OvertimeApprovalResource($approval))->response();
    }

    public function reject(OvertimeReviewRequest $request, OvertimeApproval $approval): JsonResponse
    {
        $this->authorizeApproval($request, $approval);

        $approval = $this->overtime->reject($approval, $request->user(), $request->validated()['notes'] ?? null);

        return (new OvertimeApprovalResource($approval))->response();
    }

    private function authorizeApproval(Request $request, OvertimeApproval $approval): void
    {
        $employee = $approval->employee ?? Employee::query()->find($approval->employee_id);
        abort_if(
            $employee === null || ! $this->scope->canAccess($request->user(), $employee, 'attendance.overtime.review'),
            404,
        );
    }
}
