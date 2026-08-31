<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;

/** Platform-wide audit view (audited, explicit cross-tenant read). */
class PlatformAuditController extends Controller
{
    public function index(TenantContext $context): JsonResponse
    {
        $logs = $context->runAsPlatform(function () {
            return AuditLog::query()
                ->orderByDesc('created_at')
                ->limit(100)
                ->get()
                ->map(fn (AuditLog $log) => [
                    'id' => $log->id,
                    'tenant_id' => $log->tenant_id,
                    'action' => $log->action,
                    'actor_type' => $log->actor_type,
                    'actor_label' => $log->actor_label,
                    'created_at' => $log->created_at,
                ]);
        });

        return response()->json(['data' => $logs]);
    }
}
