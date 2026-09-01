<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Enums\AttendanceSource;
use App\Modules\Attendance\Http\Requests\AttendanceFilterRequest;
use App\Modules\Attendance\Http\Requests\CorrectionRequest;
use App\Modules\Attendance\Http\Requests\PunchRequest;
use App\Modules\Attendance\Http\Resources\AttendanceCorrectionResource;
use App\Modules\Attendance\Http\Resources\AttendanceRecordResource;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Services\AttendanceCorrectionService;
use App\Modules\Attendance\Services\AttendanceSettingsService;
use App\Modules\Attendance\Services\CheckInService;
use App\Modules\Attendance\Services\CheckOutService;
use App\Modules\Attendance\Support\PunchInput;
use App\Modules\Attendance\Support\ResolvesActingEmployee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Employee self-service attendance. The acting employee is derived from the
 * authenticated user (never from the request body), so a user can only ever
 * punch or view their OWN attendance. No RBAC permission is required — an
 * authenticated, employee-linked user is the gate.
 */
class AttendanceController extends Controller
{
    use ResolvesActingEmployee;

    public function __construct(
        private readonly CheckInService $checkInService,
        private readonly CheckOutService $checkOutService,
        private readonly AttendanceCorrectionService $corrections,
        private readonly AttendanceSettingsService $settings,
    ) {}

    public function checkIn(PunchRequest $request): JsonResponse
    {
        $employee = $this->requireActingEmployee($request->user());
        $record = $this->checkInService->checkIn(
            $employee,
            PunchInput::fromArray($request->validated(), $this->sourceFor($request)),
            $request->user(),
        );

        return (new AttendanceRecordResource($record->load('employee')))
            ->response()->setStatusCode(201);
    }

    public function checkOut(PunchRequest $request): JsonResponse
    {
        $employee = $this->requireActingEmployee($request->user());
        $record = $this->checkOutService->checkOut(
            $employee,
            PunchInput::fromArray($request->validated(), $this->sourceFor($request)),
            $request->user(),
        );

        return (new AttendanceRecordResource($record->load('employee')))->response();
    }

    /** The acting employee's own records (most recent first). */
    public function myAttendance(AttendanceFilterRequest $request): JsonResponse
    {
        $employee = $this->requireActingEmployee($request->user());

        $query = AttendanceRecord::query()
            ->with(['employee', 'sessions'])
            ->where('employee_id', $employee->getKey())
            ->orderByDesc('work_date');

        if ($from = $request->query('from')) {
            $query->whereDate('work_date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('work_date', '<=', $to);
        }

        $page = $query->paginate(min((int) $request->query('per_page', 31), 100));

        return AttendanceRecordResource::collection($page)->response();
    }

    /** The acting employee's current open record, if any. */
    public function myToday(Request $request): JsonResponse
    {
        $employee = $this->requireActingEmployee($request->user());

        $open = AttendanceRecord::query()
            ->with('employee')
            ->where('employee_id', $employee->getKey())
            ->whereNotNull('check_in_at')
            ->whereNull('check_out_at')
            ->first();

        return response()->json([
            'open' => $open ? new AttendanceRecordResource($open) : null,
        ]);
    }

    /** Request a correction on the acting employee's OWN record. */
    public function requestCorrection(CorrectionRequest $request, AttendanceRecord $record): JsonResponse
    {
        $employee = $this->requireActingEmployee($request->user());

        abort_if($record->employee_id !== $employee->getKey(), 404);
        abort_unless($this->settings->current()->allow_employee_correction_request, 403,
            __('attendance.employee_corrections_disabled'));

        $correction = $this->corrections->request($record, $request->validated(), $request->user());

        return (new AttendanceCorrectionResource($correction))->response()->setStatusCode(201);
    }

    private function sourceFor(Request $request): AttendanceSource
    {
        return $request->boolean('mobile') ? AttendanceSource::Mobile : AttendanceSource::Web;
    }
}
