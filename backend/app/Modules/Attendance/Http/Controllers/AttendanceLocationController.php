<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Http\Requests\AttendanceLocationRequest;
use App\Modules\Attendance\Http\Resources\AttendanceLocationResource;
use App\Modules\Attendance\Models\AttendanceLocation;
use App\Modules\Audit\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Geofence location management (tenant-scoped by RLS). Locations define WHERE a
 * punch is considered "inside"; the server, not the client, decides membership.
 */
class AttendanceLocationController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $locations = AttendanceLocation::query()->orderBy('name')->get();

        return AttendanceLocationResource::collection($locations)->response();
    }

    public function store(AttendanceLocationRequest $request): JsonResponse
    {
        $location = AttendanceLocation::query()->create($request->validated());

        $this->audit->log('attendance.location_created', [
            'actor' => $request->user(),
            'subject' => $location,
        ]);

        return (new AttendanceLocationResource($location))->response()->setStatusCode(201);
    }

    public function update(AttendanceLocationRequest $request, AttendanceLocation $location): JsonResponse
    {
        $location->fill($request->validated())->save();

        $this->audit->log('attendance.location_updated', [
            'actor' => $request->user(),
            'subject' => $location,
        ]);

        return (new AttendanceLocationResource($location))->response();
    }

    public function archive(Request $request, AttendanceLocation $location): JsonResponse
    {
        $location->update(['status' => 'archived']);

        $this->audit->log('attendance.location_archived', [
            'actor' => $request->user(),
            'subject' => $location,
        ]);

        return (new AttendanceLocationResource($location))->response();
    }
}
