<?php

namespace App\Modules\Audit\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;

class AuditLogController extends Controller
{
    /** The active tenant's audit trail (RLS-enforced, newest first). */
    public function index(TenantContext $context): JsonResponse
    {
        $logs = AuditLog::query()
            ->where('tenant_id', $context->tenantId()) // belt; RLS is suspenders
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'actor_type' => $log->actor_type,
                'actor_label' => $log->actor_label,
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'metadata' => $log->metadata,
                'created_at' => $log->created_at,
            ]);

        return response()->json(['data' => $logs]);
    }
}
