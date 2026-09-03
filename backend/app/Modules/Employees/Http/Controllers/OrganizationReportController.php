<?php

namespace App\Modules\Employees\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Employees\Http\Requests\OrganizationTurnoverRequest;
use App\Modules\Employees\Services\OrganizationReportService;
use App\Modules\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aggregate organization / workforce reports (Sprint 8A), gated by
 * employees.reports.view and scoped by EmployeeScopeResolver. Read-only; returns
 * only neutral headcount/turnover aggregates — never a sensitive employee field.
 */
class OrganizationReportController extends Controller
{
    public function __construct(
        private readonly OrganizationReportService $reports,
        private readonly TenantContext $context,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->reports->summary($request->user()),
            'meta' => $this->meta([]),
        ]);
    }

    public function turnover(OrganizationTurnoverRequest $request): JsonResponse
    {
        $tz = $this->timezone();
        [$from, $to] = $request->window($tz);

        return response()->json([
            'data' => $this->reports->turnover($request->user(), $from, $to),
            'meta' => $this->meta(['from' => $from->toDateString(), 'to' => $to->toDateString()]),
        ]);
    }

    private function timezone(): string
    {
        return $this->context->tenant()?->timezone ?: config('app.timezone', 'UTC');
    }

    /** @param  array<string, mixed>  $filters */
    private function meta(array $filters): array
    {
        return [
            'filters' => $filters,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'timezone' => $this->timezone(),
        ];
    }
}
