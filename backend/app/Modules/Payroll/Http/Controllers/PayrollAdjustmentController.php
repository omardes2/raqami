<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payroll\Http\Requests\StorePayrollAdjustmentRequest;
use App\Modules\Payroll\Http\Requests\UpdatePayrollAdjustmentRequest;
use App\Modules\Payroll\Http\Resources\PayrollAdjustmentResource;
use App\Modules\Payroll\Models\PayrollAdjustment;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollAdjustmentService;
use App\Modules\Payroll\Support\PayrollAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Manual payroll adjustments (Phase 2B), owned by (period, employee). Company-level
 * payroll authority only (a branch/dept/team-scoped grant gets a scope-safe 404).
 * Adjustments are authoritative period inputs, mutable only while the period is open
 * and its active run pre-approval; the service and DB triggers enforce that.
 */
class PayrollAdjustmentController extends Controller
{
    public function __construct(
        private readonly PayrollAdjustmentService $adjustments,
        private readonly PayrollAuthorizationService $authz,
    ) {}

    public function index(Request $request, PayrollPeriod $period): JsonResponse
    {
        $this->authz->authorize($request->user(), 'payroll.runs.view');

        $rows = PayrollAdjustment::query()
            ->where('payroll_period_id', $period->getKey())
            ->orderBy('employee_id')->orderBy('id')
            ->get();

        return PayrollAdjustmentResource::collection($rows)->response();
    }

    public function store(StorePayrollAdjustmentRequest $request, PayrollPeriod $period): JsonResponse
    {
        $this->authz->authorize($request->user(), 'payroll.adjust');

        $data = $request->validated();
        $adjustment = $this->adjustments->create($request->user(), $period, (string) $data['employee_id'], $data);

        return (new PayrollAdjustmentResource($adjustment))->response()->setStatusCode(201);
    }

    public function update(UpdatePayrollAdjustmentRequest $request, PayrollAdjustment $adjustment): JsonResponse
    {
        $this->authz->authorize($request->user(), 'payroll.adjust');

        $adjustment = $this->adjustments->update($request->user(), $adjustment, $request->validated());

        return (new PayrollAdjustmentResource($adjustment))->response();
    }

    public function destroy(Request $request, PayrollAdjustment $adjustment): Response
    {
        $this->authz->authorize($request->user(), 'payroll.adjust');

        $this->adjustments->delete($request->user(), $adjustment);

        return response()->noContent();
    }
}
