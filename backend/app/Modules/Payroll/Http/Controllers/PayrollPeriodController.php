<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payroll\Http\Requests\StorePayrollPeriodRequest;
use App\Modules\Payroll\Http\Resources\PayrollPeriodResource;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollPeriodService;
use App\Modules\Payroll\Support\PayrollAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayrollPeriodController extends Controller
{
    public function __construct(
        private readonly PayrollPeriodService $periods,
        private readonly PayrollAuthorizationService $authz,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authz->authorize($request->user(), 'payroll.runs.view');

        return PayrollPeriodResource::collection($this->periods->list())->response();
    }

    public function store(StorePayrollPeriodRequest $request): JsonResponse
    {
        $this->authz->authorize($request->user(), 'payroll.runs.manage');

        return (new PayrollPeriodResource($this->periods->create($request->user(), $request->validated())))
            ->response()->setStatusCode(201);
    }

    public function show(Request $request, PayrollPeriod $period): JsonResponse
    {
        $this->authz->authorize($request->user(), 'payroll.runs.view');

        return (new PayrollPeriodResource($period))->response();
    }
}
