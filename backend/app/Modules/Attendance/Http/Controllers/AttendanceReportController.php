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
}
