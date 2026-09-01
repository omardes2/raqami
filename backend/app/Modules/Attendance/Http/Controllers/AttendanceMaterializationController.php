<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Services\AttendanceAnomalyService;
use App\Modules\Attendance\Services\AttendanceDayMaterializer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * On-demand daily materialization for the CURRENT tenant (company scope). The
 * scheduled command covers all tenants; this lets an admin re-run a single day
 * (idempotent). Runs within the caller's tenant context, so RLS confines it.
 */
class AttendanceMaterializationController extends Controller
{
    public function __construct(
        private readonly AttendanceDayMaterializer $materializer,
        private readonly AttendanceAnomalyService $anomalies,
    ) {}

    public function run(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date', 'before_or_equal:tomorrow'],
        ]);

        $date = CarbonImmutable::parse($validated['date'])->startOfDay();

        $result = $this->materializer->materialize($date);
        $result['anomalies'] = $this->anomalies->detect($date);

        return response()->json([
            'date' => $date->toDateString(),
            'result' => $result,
        ]);
    }
}
