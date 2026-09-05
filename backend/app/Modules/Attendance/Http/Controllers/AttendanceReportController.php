<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Http\Requests\AttendanceFilterRequest;
use App\Modules\Attendance\Services\AttendanceReportService;
use Illuminate\Http\JsonResponse;

/** Basic attendance reporting (scope-constrained aggregates). */
class AttendanceReportController extends Controller
{
    public function __construct(private readonly AttendanceReportService $reports) {}

    public function summary(AttendanceFilterRequest $request): JsonResponse
    {
        $filters = $request->filters();

        return response()->json([
            'filters' => $filters,
            'summary' => $this->reports->summary($request->user(), $filters),
        ]);
    }

    /**
     * Advanced compliance report: neutral attendance/punctuality rates, a full
     * status breakdown, and the calculated-vs-approved overtime rollup. No raw
     * GPS is ever included.
     */
    public function advanced(AttendanceFilterRequest $request): JsonResponse
    {
        $filters = $request->filters();
        $user = $request->user();

        return response()->json([
            'filters' => $filters,
            'compliance' => $this->reports->compliance($user, $filters),
            'status_breakdown' => $this->reports->statusBreakdown($user, $filters),
            'overtime' => $this->reports->overtime($user, $filters),
        ]);
    }

    /** Per-employee rollup (counts + server-computed minutes; no GPS). */
    public function byEmployee(AttendanceFilterRequest $request): JsonResponse
    {
        $filters = $request->filters();

        return response()->json([
            'filters' => $filters,
            'employees' => $this->reports->byEmployee($request->user(), $filters),
        ]);
    }

    /**
     * Organization rollup grouped by branch or department (Sprint 8A gap).
     * group_by is whitelisted to branch|department (defaults to branch).
     */
    public function byUnit(AttendanceFilterRequest $request): JsonResponse
    {
        $filters = $request->filters();
        $groupBy = $request->query('group_by') === 'department' ? 'department' : 'branch';

        return response()->json([
            'filters' => $filters + ['group_by' => $groupBy],
            'units' => $this->reports->byUnit($request->user(), $filters, $groupBy),
        ]);
    }
}
