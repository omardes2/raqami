<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payroll\Enums\PayrollEntryStatus;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Http\Resources\OwnPayslipDetailResource;
use App\Modules\Payroll\Http\Resources\OwnPayslipResource;
use App\Modules\Payroll\Models\PayrollEntry;
use App\Modules\Payroll\Support\PayrollAuthorizationService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Employee self-service payslips (Sprint 7.5) — READ-ONLY over immutable, FINALIZED
 * payroll history. A payslip is exactly one finalized payroll_entry whose run is
 * finalized and whose period is closed; there is no payslip table. Access requires
 * BOTH the payroll.view_own permission (route gate) AND the actor's own linked
 * Employee (resolved for the CURRENT tenant only). Every other employee's, every
 * cross-tenant, and every non-finalized entry is a scope-safe 404. Tenant isolation
 * is additionally enforced by FORCE RLS on all payroll tables. No mutation exists.
 */
class MePayslipController extends Controller
{
    public function __construct(
        private readonly PayrollAuthorizationService $authz,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $employeeId = $this->authz->actorEmployeeId($request->user());

        $query = $employeeId === null
            ? PayrollEntry::query()->whereRaw('1 = 0')
            : $this->finalizedOwnQuery($employeeId)
                ->with('run.period')
                ->orderByDesc('payroll_periods.period_start')
                ->orderByDesc('payroll_entries.id');

        return OwnPayslipResource::collection($query->paginate(12))->response();
    }

    public function show(Request $request, string $entry): JsonResponse
    {
        $employeeId = $this->authz->actorEmployeeId($request->user());
        abort_if($employeeId === null, 404);

        $payslip = $this->finalizedOwnQuery($employeeId)
            ->where('payroll_entries.id', $entry)
            ->with(['run.period', 'lines' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])
            ->first();
        abort_if($payslip === null, 404);

        // Safe company display identity (no new branding system).
        $payslip->company_name = $this->context->tenant()?->name;

        return (new OwnPayslipDetailResource($payslip))->response();
    }

    /**
     * Own FINALIZED payslips only: entry finalized, run finalized, period closed.
     * Joins keep the status predicates on the authoritative parents; all three
     * tables are tenant-scoped under FORCE RLS.
     */
    private function finalizedOwnQuery(string $employeeId): Builder
    {
        return PayrollEntry::query()
            ->select('payroll_entries.*')
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_entries.payroll_run_id')
            ->join('payroll_periods', 'payroll_periods.id', '=', 'payroll_runs.payroll_period_id')
            ->where('payroll_entries.employee_id', $employeeId)
            ->where('payroll_entries.status', PayrollEntryStatus::Finalized->value)
            ->where('payroll_runs.status', PayrollRunStatus::Finalized->value)
            ->where('payroll_periods.status', PayrollPeriodStatus::Closed->value);
    }
}
