<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Services\AttendanceReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Basic attendance reporting (scope-constrained aggregates). */
class AttendanceReportController extends Controller
{
    public function __construct(private readonly AttendanceReportService $reports) {}

    public function summary(Request $request): JsonResponse
    {
        $filters = [
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'employee_id' => $request->query('employee_id'),
            'status' => $request->query('status'),
        ];

        return response()->json([
            'filters' => $filters,
            'summary' => $this->reports->summary($request->user(), $filters),
        ]);
    }
}
