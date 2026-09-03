<?php

namespace App\Modules\Tasks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tasks\Services\TaskReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskReportController extends Controller
{
    public function __construct(private readonly TaskReportService $reports) {}

    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'by_status' => $this->reports->summaryByStatus($user),
            'by_priority' => $this->reports->summaryByPriority($user),
            'overdue' => $this->reports->overdueCount($user),
        ]]);
    }

    public function workload(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->reports->workload($request->user())]);
    }
}
