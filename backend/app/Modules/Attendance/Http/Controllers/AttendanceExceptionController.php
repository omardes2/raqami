<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Http\Requests\AttendanceExceptionRequest;
use App\Modules\Attendance\Http\Resources\AttendanceExceptionResource;
use App\Modules\Attendance\Models\AttendanceException;
use App\Modules\Attendance\Services\AttendanceExceptionService;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Support\EmployeeScopeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Authorized attendance exceptions (remote/field/off-day/alternate). Scope-checked
 * against the target employee: a manager can only create/see exceptions for
 * employees within their organizational scope. Employees never self-declare.
 */
class AttendanceExceptionController extends Controller
{
    public function __construct(
        private readonly AttendanceExceptionService $exceptions,
        private readonly EmployeeScopeResolver $scope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = AttendanceException::query()
            ->whereHas('employee', fn ($q) => $this->scope->applyScope($q, $request->user(), 'attendance.exceptions.view'))
            ->orderByDesc('effective_from');

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->query('employee_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return AttendanceExceptionResource::collection(
            $query->paginate(min((int) $request->query('per_page', 20), 100))
        )->response();
    }

    public function store(AttendanceExceptionRequest $request): JsonResponse
    {
        $employee = Employee::query()->findOrFail($request->validated('employee_id'));

        abort_unless(
            $this->scope->canAccess($request->user(), $employee, 'attendance.exceptions.manage'),
            404,
        );

        $exception = $this->exceptions->create($employee, $request->validated(), $request->user());

        return (new AttendanceExceptionResource($exception))->response()->setStatusCode(201);
    }

    public function revoke(Request $request, AttendanceException $exception): JsonResponse
    {
        $employee = $exception->employee ?? Employee::query()->find($exception->employee_id);
        abort_if(
            $employee === null || ! $this->scope->canAccess($request->user(), $employee, 'attendance.exceptions.manage'),
            404,
        );

        $exception = $this->exceptions->revoke($exception, $request->user());

        return (new AttendanceExceptionResource($exception))->response();
    }
}
