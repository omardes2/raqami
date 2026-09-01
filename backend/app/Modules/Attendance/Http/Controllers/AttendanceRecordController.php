<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Http\Requests\ManualAttendanceRequest;
use App\Modules\Attendance\Http\Resources\AttendanceRecordResource;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Services\AttendanceReportService;
use App\Modules\Attendance\Services\ManualAttendanceService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Support\EmployeeScopeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin/HR view over attendance records. Every listing and lookup is constrained
 * to the caller's organizational scope (EmployeeScopeResolver), so a manager can
 * never read another branch's attendance. Manual entry is scope-checked against
 * the target employee.
 */
class AttendanceRecordController extends Controller
{
    public function __construct(
        private readonly AttendanceReportService $reports,
        private readonly ManualAttendanceService $manual,
        private readonly EmployeeScopeResolver $scope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->reports->scopedRecords($request->user(), [
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'employee_id' => $request->query('employee_id'),
            'status' => $request->query('status'),
        ])->with('employee')->orderByDesc('work_date');

        $page = $query->paginate(min((int) $request->query('per_page', 20), 100));

        return AttendanceRecordResource::collection($page)->response();
    }

    public function show(Request $request, AttendanceRecord $record): JsonResponse
    {
        $this->authorizeRecord($request, $record);

        return (new AttendanceRecordResource($record->load(['employee', 'events'])))->response();
    }

    public function storeManual(ManualAttendanceRequest $request): JsonResponse
    {
        $employee = Employee::query()->findOrFail($request->validated('employee_id'));

        // Must be able to manage attendance for THIS employee's scope.
        abort_unless(
            $this->scope->canAccess($request->user(), $employee, 'attendance.manage'),
            404,
        );

        $record = $this->manual->record($employee, $request->validated(), $request->user());

        return (new AttendanceRecordResource($record->load('employee')))->response()->setStatusCode(201);
    }

    private function authorizeRecord(Request $request, AttendanceRecord $record): void
    {
        $employee = $record->employee ?? Employee::query()->find($record->employee_id);

        abort_if(
            $employee === null || ! $this->scope->canAccess($request->user(), $employee, 'attendance.view'),
            404,
        );
    }
}
