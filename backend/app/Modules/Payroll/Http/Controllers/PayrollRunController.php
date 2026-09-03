<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payroll\Http\Requests\StorePayrollRunRequest;
use App\Modules\Payroll\Http\Resources\PayrollRunResource;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Payroll\Support\PayrollAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayrollRunController extends Controller
{
    public function __construct(
        private readonly PayrollRunService $runs,
        private readonly PayrollAuthorizationService $authz,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authz->authorize($request->user(), 'payroll.runs.view');

        $runs = PayrollRun::query()
            ->when($request->filled('payroll_period_id'), fn ($q) => $q->where('payroll_period_id', $request->query('payroll_period_id')))
            ->orderByDesc('created_at')
            ->get();

        return PayrollRunResource::collection($runs)->response();
    }

    public function store(StorePayrollRunRequest $request): JsonResponse
    {
        $this->authz->authorize($request->user(), 'payroll.runs.manage');

        $period = PayrollPeriod::query()->findOrFail($request->validated()['payroll_period_id']);
        $run = $this->runs->create($request->user(), $period);

        return (new PayrollRunResource($run))->response()->setStatusCode(201);
    }

    public function show(Request $request, PayrollRun $run): JsonResponse
    {
        $this->authz->authorize($request->user(), 'payroll.runs.view');

        return (new PayrollRunResource($run))->response();
    }

    public function cancel(Request $request, PayrollRun $run): JsonResponse
    {
        $this->authz->authorize($request->user(), 'payroll.runs.manage');

        return (new PayrollRunResource($this->runs->cancel($request->user(), $run)))->response();
    }
}
