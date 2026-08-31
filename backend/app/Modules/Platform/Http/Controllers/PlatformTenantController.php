<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Read-only Super Admin views over tenants. Cross-tenant reads happen ONLY
 * through the audited, explicit platform read-only context — never silently.
 * Writes never bypass tenant scope.
 */
class PlatformTenantController extends Controller
{
    public function index(TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $tenants = $context->runAsPlatform(function () {
            return Tenant::query()
                ->withCount('memberships')
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (Tenant $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'slug' => $t->slug,
                    'status' => $t->status,
                    'members_count' => $t->memberships_count,
                    'created_at' => $t->created_at,
                ]);
        });

        $audit->log('platform.tenants.listed', [
            'actor' => Auth::guard('platform')->user(),
            'tenant_id' => null,
        ]);

        return response()->json(['data' => $tenants]);
    }

    public function show(string $tenant, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $payload = $context->runAsPlatform(function () use ($tenant) {
            $model = Tenant::query()->withCount('memberships')->findOrFail($tenant);

            return [
                'id' => $model->id,
                'name' => $model->name,
                'legal_name' => $model->legal_name,
                'slug' => $model->slug,
                'country_code' => $model->country_code,
                'status' => $model->status,
                'members_count' => $model->memberships_count,
                'created_at' => $model->created_at,
            ];
        });

        $audit->log('platform.tenant.viewed', [
            'actor' => Auth::guard('platform')->user(),
            'tenant_id' => null,
            'subject_type' => Tenant::class,
            'subject_id' => $tenant,
        ]);

        return response()->json($payload);
    }
}
