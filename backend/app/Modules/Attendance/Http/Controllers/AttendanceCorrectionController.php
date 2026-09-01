<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Enums\CorrectionStatus;
use App\Modules\Attendance\Http\Requests\CorrectionReviewRequest;
use App\Modules\Attendance\Http\Resources\AttendanceCorrectionResource;
use App\Modules\Attendance\Models\AttendanceCorrection;
use App\Modules\Attendance\Services\AttendanceCorrectionService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Support\EmployeeScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Review workflow for attendance corrections. Reviewers see only corrections for
 * employees in their scope and can never approve their own request (segregation
 * of duties enforced in the service).
 */
class AttendanceCorrectionController extends Controller
{
    public function __construct(
        private readonly AttendanceCorrectionService $corrections,
        private readonly EmployeeScopeResolver $scope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = AttendanceCorrection::query()
            ->whereHas('employee', fn (Builder $q) => $this->scope->applyScope($q, $request->user(), 'attendance.corrections.review'))
            ->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $page = $query->paginate(min((int) $request->query('per_page', 20), 100));

        return AttendanceCorrectionResource::collection($page)->response();
    }

    public function approve(Request $request, AttendanceCorrection $correction): JsonResponse
    {
        $this->authorizeCorrection($request, $correction);

        $updated = $this->corrections->approve($correction, $request->user());

        return (new AttendanceCorrectionResource($updated))->response();
    }

    public function reject(CorrectionReviewRequest $request, AttendanceCorrection $correction): JsonResponse
    {
        $this->authorizeCorrection($request, $correction);

        $updated = $this->corrections->rejectRequest(
            $correction,
            $request->user(),
            (string) $request->validated('rejection_reason'),
        );

        return (new AttendanceCorrectionResource($updated))->response();
    }

    private function authorizeCorrection(Request $request, AttendanceCorrection $correction): void
    {
        $employee = Employee::query()->find($correction->employee_id);

        abort_if(
            $employee === null || ! $this->scope->canAccess($request->user(), $employee, 'attendance.corrections.review'),
            404,
        );

        abort_if($correction->status !== CorrectionStatus::Pending, 422, 'This correction has already been reviewed.');
    }
}
