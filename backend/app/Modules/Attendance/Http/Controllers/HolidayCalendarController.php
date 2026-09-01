<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Http\Requests\HolidayAssignmentRequest;
use App\Modules\Attendance\Http\Requests\HolidayCalendarRequest;
use App\Modules\Attendance\Http\Requests\HolidayRequest;
use App\Modules\Attendance\Http\Resources\HolidayCalendarResource;
use App\Modules\Attendance\Http\Resources\HolidayResource;
use App\Modules\Attendance\Models\Holiday;
use App\Modules\Attendance\Models\HolidayCalendar;
use App\Modules\Attendance\Models\HolidayCalendarAssignment;
use App\Modules\Attendance\Services\HolidayCalendarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Holiday calendars, their dates, and company/branch assignments. Company-scope
 * config (shared, not per-employee rows); tenant-scoped by RLS.
 */
class HolidayCalendarController extends Controller
{
    public function __construct(private readonly HolidayCalendarService $calendars) {}

    public function index(Request $request): JsonResponse
    {
        $calendars = HolidayCalendar::query()
            ->with(['holidays', 'assignments'])
            ->orderBy('name')
            ->get();

        return HolidayCalendarResource::collection($calendars)->response();
    }

    public function show(HolidayCalendar $calendar): JsonResponse
    {
        return (new HolidayCalendarResource($calendar->load(['holidays', 'assignments'])))->response();
    }

    public function store(HolidayCalendarRequest $request): JsonResponse
    {
        $calendar = $this->calendars->createCalendar($request->validated(), $request->user());

        return (new HolidayCalendarResource($calendar))->response()->setStatusCode(201);
    }

    public function update(HolidayCalendarRequest $request, HolidayCalendar $calendar): JsonResponse
    {
        $calendar = $this->calendars->updateCalendar($calendar, $request->validated(), $request->user());

        return (new HolidayCalendarResource($calendar))->response();
    }

    public function addHoliday(HolidayRequest $request, HolidayCalendar $calendar): JsonResponse
    {
        $holiday = $this->calendars->addHoliday($calendar, $request->validated(), $request->user());

        return (new HolidayResource($holiday))->response()->setStatusCode(201);
    }

    public function deleteHoliday(Request $request, HolidayCalendar $calendar, Holiday $holiday): JsonResponse
    {
        abort_if($holiday->holiday_calendar_id !== $calendar->getKey(), 404);

        $this->calendars->deleteHoliday($holiday, $request->user());

        return response()->json(['deleted' => true]);
    }

    public function assign(HolidayAssignmentRequest $request, HolidayCalendar $calendar): JsonResponse
    {
        $assignment = $this->calendars->assign($calendar, $request->validated(), $request->user());

        return (new HolidayCalendarResource($calendar->fresh(['holidays', 'assignments'])))
            ->additional(['assignment_id' => $assignment->id])
            ->response()->setStatusCode(201);
    }

    public function unassign(Request $request, HolidayCalendar $calendar, HolidayCalendarAssignment $assignment): JsonResponse
    {
        abort_if($assignment->holiday_calendar_id !== $calendar->getKey(), 404);

        $this->calendars->unassign($assignment, $request->user());

        return response()->json(['deleted' => true]);
    }
}
