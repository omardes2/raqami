<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payroll\Http\Requests\PayrollReportRequest;
use App\Modules\Payroll\Services\PayrollReportService;
use App\Modules\Payroll\Support\PayrollAuthorizationService;
use App\Modules\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * Company-wide payroll reports (Sprint 8A Phase 2), gated by the existing
 * payroll.reports.view permission enforced at COMPANY scope via
 * PayrollAuthorizationService (a branch/department/team-scoped grant gets a
 * scope-safe 404 — salary is never exposed to a scoped grant). Financial reports
 * are finalized-only and grouped by currency; the run-status report is operational
 * and carries no money.
 */
class PayrollReportController extends Controller
{
    public function __construct(
        private readonly PayrollReportService $reports,
        private readonly PayrollAuthorizationService $authz,
        private readonly TenantContext $context,
    ) {}

    public function summary(PayrollReportRequest $request): JsonResponse
    {
        $this->authz->authorize($request->user(), 'payroll.reports.view');

        return $this->respond($this->reports->summary($request->filters()), $request->filters());
    }

    public function byPeriod(PayrollReportRequest $request): JsonResponse
    {
        $this->authz->authorize($request->user(), 'payroll.reports.view');

        return $this->respond($this->reports->byPeriod($request->filters()), $request->filters());
    }

    public function byEmployee(PayrollReportRequest $request): JsonResponse
    {
        $this->authz->authorize($request->user(), 'payroll.reports.view');

        return $this->respond($this->reports->byEmployee($request->filters()), $request->filters());
    }

    public function components(PayrollReportRequest $request): JsonResponse
    {
        $this->authz->authorize($request->user(), 'payroll.reports.view');

        return $this->respond($this->reports->components($request->filters()), $request->filters());
    }

    public function runStatus(PayrollReportRequest $request): JsonResponse
    {
        $this->authz->authorize($request->user(), 'payroll.reports.view');

        return $this->respond($this->reports->runStatus(), []);
    }

    /** @param  array<string, mixed>  $filters */
    private function respond(mixed $data, array $filters): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => [
                'filters' => array_filter($filters, fn ($v) => $v !== null),
                'generated_at' => CarbonImmutable::now()->toIso8601String(),
                'timezone' => $this->context->tenant()?->timezone ?: config('app.timezone', 'UTC'),
            ],
        ]);
    }
}
