<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payroll\Http\Requests\UpdatePayrollSettingsRequest;
use App\Modules\Payroll\Http\Resources\PayrollSettingResource;
use App\Modules\Payroll\Services\PayrollSettingsService;
use App\Modules\Payroll\Support\PayrollAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayrollSettingsController extends Controller
{
    public function __construct(
        private readonly PayrollSettingsService $settings,
        private readonly PayrollAuthorizationService $authz,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $this->authz->authorize($request->user(), 'payroll.settings.manage');

        return (new PayrollSettingResource($this->settings->getOrCreate()))->response();
    }

    public function update(UpdatePayrollSettingsRequest $request): JsonResponse
    {
        $this->authz->authorize($request->user(), 'payroll.settings.manage');

        return (new PayrollSettingResource($this->settings->update($request->user(), $request->validated())))->response();
    }
}
