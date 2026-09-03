<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payroll\Http\Resources\PayrollRunResource;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\PayrollCalculationService;
use App\Modules\Payroll\Support\PayrollAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Requests (re)calculation of a payroll run. Company-level payroll authority only;
 * the heavy work runs in a queued TenantAware job, so these endpoints accept and
 * return 202 without calculating inline.
 */
class PayrollCalculationController extends Controller
{
    public function __construct(
        private readonly PayrollCalculationService $calculation,
        private readonly PayrollAuthorizationService $authz,
    ) {}

    public function calculate(Request $request, PayrollRun $run): JsonResponse
    {
        $this->authz->authorize($request->user(), 'payroll.calculate');
        $run = $this->calculation->calculate($request->user(), $run);

        return (new PayrollRunResource($run))->response()->setStatusCode(202);
    }

    public function recalculate(Request $request, PayrollRun $run): JsonResponse
    {
        $this->authz->authorize($request->user(), 'payroll.calculate');
        $run = $this->calculation->recalculate($request->user(), $run);

        return (new PayrollRunResource($run))->response()->setStatusCode(202);
    }
}
