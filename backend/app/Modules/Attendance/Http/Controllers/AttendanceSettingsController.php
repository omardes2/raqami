<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Http\Requests\AttendanceSettingsRequest;
use App\Modules\Attendance\Http\Resources\AttendanceSettingsResource;
use App\Modules\Attendance\Services\AttendanceSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The single per-tenant attendance policy row. */
class AttendanceSettingsController extends Controller
{
    public function __construct(private readonly AttendanceSettingsService $settings) {}

    public function show(Request $request): JsonResponse
    {
        return (new AttendanceSettingsResource($this->settings->current()))->response();
    }

    public function update(AttendanceSettingsRequest $request): JsonResponse
    {
        $settings = $this->settings->update($request->validated(), $request->user());

        return (new AttendanceSettingsResource($settings))->response();
    }
}
