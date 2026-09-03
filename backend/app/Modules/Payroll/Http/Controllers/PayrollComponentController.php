<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payroll\Http\Requests\StorePayrollComponentRequest;
use App\Modules\Payroll\Http\Requests\UpdatePayrollComponentRequest;
use App\Modules\Payroll\Http\Resources\PayrollComponentResource;
use App\Modules\Payroll\Models\PayrollComponent;
use App\Modules\Payroll\Services\PayrollComponentService;
use App\Modules\Payroll\Support\PayrollAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayrollComponentController extends Controller
{
    public function __construct(
        private readonly PayrollComponentService $components,
        private readonly PayrollAuthorizationService $authz,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authz->authorize($request->user(), 'payroll.compensation.view');

        return PayrollComponentResource::collection(
            PayrollComponent::query()->orderBy('sort_order')->orderBy('code')->get()
        )->response();
    }

    public function store(StorePayrollComponentRequest $request): JsonResponse
    {
        $this->authz->authorize($request->user(), 'payroll.components.manage');

        return (new PayrollComponentResource($this->components->create($request->user(), $request->validated())))
            ->response()->setStatusCode(201);
    }

    public function update(UpdatePayrollComponentRequest $request, PayrollComponent $component): JsonResponse
    {
        $this->authz->authorize($request->user(), 'payroll.components.manage');

        return (new PayrollComponentResource($this->components->update($request->user(), $component, $request->validated())))->response();
    }
}
