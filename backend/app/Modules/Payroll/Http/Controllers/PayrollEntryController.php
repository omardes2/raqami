<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payroll\Http\Resources\PayrollEntryResource;
use App\Modules\Payroll\Models\PayrollEntry;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\PayrollRunSummaryService;
use App\Modules\Payroll\Support\PayrollAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Management review of calculated payroll entries. Company-level payroll authority
 * only (a branch/dept/team-scoped grant gets a scope-safe 404). No employee
 * self-service, no raw private leave data.
 */
class PayrollEntryController extends Controller
{
    public function __construct(
        private readonly PayrollAuthorizationService $authz,
        private readonly PayrollRunSummaryService $summaries,
    ) {}

    public function index(Request $request, PayrollRun $run): JsonResponse
    {
        $this->authz->authorize($request->user(), 'payroll.runs.view');

        $entries = PayrollEntry::query()
            ->where('payroll_run_id', $run->getKey())
            ->with('employee')
            ->orderBy('id')
            ->get();

        return PayrollEntryResource::collection($entries)->response();
    }

    public function summary(Request $request, PayrollRun $run): JsonResponse
    {
        $this->authz->authorize($request->user(), 'payroll.runs.view');

        return response()->json($this->summaries->summary($run));
    }

    public function show(Request $request, PayrollEntry $entry): JsonResponse
    {
        $this->authz->authorize($request->user(), 'payroll.runs.view');

        $entry->load(['employee', 'lines' => fn ($q) => $q->orderBy('sort_order')]);

        return (new PayrollEntryResource($entry))->response();
    }
}
