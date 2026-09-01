<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Http\Requests\ScheduleAssignmentRequest;
use App\Modules\Attendance\Http\Requests\WorkScheduleRequest;
use App\Modules\Attendance\Http\Resources\WorkScheduleResource;
use App\Modules\Attendance\Models\WorkSchedule;
use App\Modules\Attendance\Models\WorkScheduleAssignment;
use App\Modules\Attendance\Services\WorkScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Company-wide work schedule configuration. Tenant-scoped by RLS; permission is
 * company-scope (schedules are shared config, not per-org rows).
 */
class WorkScheduleController extends Controller
{
    public function __construct(private readonly WorkScheduleService $schedules) {}

    public function index(Request $request): JsonResponse
    {
        $schedules = WorkSchedule::query()
            ->with(['days', 'assignments'])
            ->orderBy('name')
            ->get();

        return WorkScheduleResource::collection($schedules)->response();
    }

    public function show(WorkSchedule $schedule): JsonResponse
    {
        return (new WorkScheduleResource($schedule->load(['days', 'assignments'])))->response();
    }

    public function store(WorkScheduleRequest $request): JsonResponse
    {
        $data = $request->validated();
        $schedule = $this->schedules->create(
            collect($data)->except('days')->all(),
            $data['days'],
            $request->user(),
        );

        return (new WorkScheduleResource($schedule->load(['days', 'assignments'])))
            ->response()->setStatusCode(201);
    }

    public function update(WorkScheduleRequest $request, WorkSchedule $schedule): JsonResponse
    {
        $data = $request->validated();
        $schedule = $this->schedules->update(
            $schedule,
            collect($data)->except('days')->all(),
            $data['days'] ?? null,
            $request->user(),
        );

        return (new WorkScheduleResource($schedule->load(['days', 'assignments'])))->response();
    }

    public function assign(ScheduleAssignmentRequest $request, WorkSchedule $schedule): JsonResponse
    {
        $assignment = $this->schedules->assign($schedule, $request->validated(), $request->user());

        return (new WorkScheduleResource($schedule->fresh(['days', 'assignments'])))
            ->additional(['assignment_id' => $assignment->id])
            ->response()->setStatusCode(201);
    }

    public function unassign(Request $request, WorkSchedule $schedule, WorkScheduleAssignment $assignment): JsonResponse
    {
        abort_if($assignment->work_schedule_id !== $schedule->getKey(), 404);

        $this->schedules->unassign($assignment, $request->user());

        return response()->json(['deleted' => true]);
    }
}
