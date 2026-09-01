<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Enums\AnomalyStatus;
use App\Modules\Attendance\Http\Requests\AnomalyReviewRequest;
use App\Modules\Attendance\Http\Resources\AttendanceAnomalyResource;
use App\Modules\Attendance\Models\AttendanceAnomaly;
use App\Modules\Attendance\Services\AttendanceAnomalyService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Support\EmployeeScopeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Attendance anomaly review. Findings are neutral (never fraud); this endpoint
 * only lists them and transitions their review state. Scope-checked against the
 * target employee. No disciplinary action is ever taken here.
 */
class AttendanceAnomalyController extends Controller
{
    public function __construct(
        private readonly AttendanceAnomalyService $anomalies,
        private readonly EmployeeScopeResolver $scope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = AttendanceAnomaly::query()
            ->whereHas('employee', fn ($q) => $this->scope->applyScope($q, $request->user(), 'attendance.anomalies.view'))
            ->orderByDesc('detected_at');

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->query('employee_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        return AttendanceAnomalyResource::collection(
            $query->paginate(min((int) $request->query('per_page', 20), 100))
        )->response();
    }

    public function review(AnomalyReviewRequest $request, AttendanceAnomaly $anomaly): JsonResponse
    {
        $employee = $anomaly->employee ?? Employee::query()->find($anomaly->employee_id);
        abort_if(
            $employee === null || ! $this->scope->canAccess($request->user(), $employee, 'attendance.anomalies.manage'),
            404,
        );

        $anomaly = $this->anomalies->resolve(
            $anomaly,
            $request->user(),
            AnomalyStatus::from($request->validated()['status']),
            $request->validated()['note'] ?? null,
        );

        return (new AttendanceAnomalyResource($anomaly))->response();
    }
}
