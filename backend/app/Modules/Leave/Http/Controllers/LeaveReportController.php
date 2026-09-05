<?php

namespace App\Modules\Leave\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Support\EmployeeScopeResolver;
use App\Modules\Leave\Services\LeaveReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Neutral leave reporting + team calendar (scoped). Minutes only — no monetary
 * liability, no medical/reason detail in the calendar feed.
 */
class LeaveReportController extends Controller
{
    public function __construct(
        private readonly LeaveReportService $reports,
        private readonly EmployeeScopeResolver $scope,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $scoped = Employee::query();
        $this->scope->applyScope($scoped, $request->user(), 'leave.reports.view');

        return response()->json(['data' => $this->reports->summary($scoped, $from, $to)]);
    }

    public function requestsByStatus(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $scoped = Employee::query();
        $this->scope->applyScope($scoped, $request->user(), 'leave.reports.view');

        return response()->json(['data' => $this->reports->requestsByStatus($scoped, $from, $to)]);
    }

    public function calendar(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $scoped = Employee::query();
        $this->scope->applyScope($scoped, $request->user(), 'leave.reports.view');

        $includePending = $request->boolean('include_pending');

        return response()->json(['data' => $this->reports->calendar($scoped, $from, $to, $includePending)]);
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} */
    private function range(Request $request): array
    {
        $from = $request->filled('from') ? CarbonImmutable::parse($request->query('from')) : CarbonImmutable::now()->startOfMonth();
        $to = $request->filled('to') ? CarbonImmutable::parse($request->query('to')) : $from->addMonths(2)->endOfMonth();

        return [$from->startOfDay(), $to->startOfDay()];
    }
}
