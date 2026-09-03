<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Http\Requests\StorePayrollAdjustmentRequest;
use App\Modules\Payroll\Http\Resources\PayrollAdjustmentResource;
use App\Modules\Payroll\Models\PayrollAdjustment;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\PayrollAdjustmentService;
use App\Modules\Payroll\Support\PayrollAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Manual payroll adjustments (Phase 2B). Company-level payroll authority only (a
 * branch/dept/team-scoped grant gets a scope-safe 404). Adjustments feed the
 * calculation as authoritative inputs, so they may only change while the run is
 * pre-approval; the service and DB triggers enforce that.
 */
class PayrollAdjustmentController extends Controller
{
    public function __construct(
        private readonly PayrollAdjustmentService $adjustments,
        private readonly PayrollAuthorizationService $authz,
    ) {}

    public function index(Request $request, PayrollRun $run): JsonResponse
    {
        $this->authz->authorize($request->user(), 'payroll.runs.view');

        $rows = PayrollAdjustment::query()
            ->where('payroll_run_id', $run->getKey())
            ->orderBy('employee_id')->orderBy('id')
            ->get();

        return PayrollAdjustmentResource::collection($rows)->response();
    }

    public function store(StorePayrollAdjustmentRequest $request, PayrollRun $run, Employee $employee): JsonResponse
    {
        $this->authz->authorize($request->user(), 'payroll.adjust');

        $adjustment = $this->adjustments->create($request->user(), $run, (string) $employee->getKey(), $request->validated());

        return (new PayrollAdjustmentResource($adjustment))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, PayrollAdjustment $adjustment): Response
    {
        $this->authz->authorize($request->user(), 'payroll.adjust');

        $this->adjustments->delete($request->user(), $adjustment);

        return response()->noContent();
    }
}
