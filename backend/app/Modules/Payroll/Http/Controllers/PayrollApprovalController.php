<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payroll\Http\Requests\FinalizePayrollRunRequest;
use App\Modules\Payroll\Http\Resources\PayrollRunResource;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\PayrollApprovalService;
use App\Modules\Payroll\Services\PayrollFinalizationService;
use App\Modules\Payroll\Support\PayrollAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Payroll run approval + finalization (Phase 2B). Company-level payroll authority
 * only (a branch/dept/team-scoped grant gets a scope-safe 404). Four-eyes,
 * self-payroll, staleness, cohort-currency, and negative-net override are enforced
 * in the services; these endpoints only gate the coarse permission.
 */
class PayrollApprovalController extends Controller
{
    public function __construct(
        private readonly PayrollApprovalService $approvals,
        private readonly PayrollFinalizationService $finalization,
        private readonly PayrollAuthorizationService $authz,
    ) {}

    public function approve(Request $request, PayrollRun $run): JsonResponse
    {
        $this->authz->authorize($request->user(), 'payroll.approve');

        $run = $this->approvals->approve($request->user(), $run);

        return (new PayrollRunResource($run))->response();
    }

    public function finalize(FinalizePayrollRunRequest $request, PayrollRun $run): JsonResponse
    {
        $this->authz->authorize($request->user(), 'payroll.finalize');

        $run = $this->finalization->finalize(
            $request->user(),
            $run,
            $request->validated()['negative_net_override_reason'] ?? null,
        );

        return (new PayrollRunResource($run))->response();
    }
}
