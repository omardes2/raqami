<?php

namespace App\Modules\Leave\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Leave\Http\Requests\LeaveSettingsRequest;
use App\Modules\Leave\Http\Resources\LeaveSettingsResource;
use App\Modules\Leave\Services\LeaveSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Company-scoped per-tenant leave settings. */
class LeaveSettingsController extends Controller
{
    public function __construct(private readonly LeaveSettingsService $settings) {}

    public function show(Request $request): JsonResponse
    {
        return (new LeaveSettingsResource($this->settings->current()))->response();
    }

    public function update(LeaveSettingsRequest $request): JsonResponse
    {
        $settings = $this->settings->update($request->validated(), $request->user());

        return (new LeaveSettingsResource($settings))->response();
    }
}
