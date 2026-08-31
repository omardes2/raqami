<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\TenantMembership;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MembershipController extends Controller
{
    public function activate(string $membership, Request $request, AuditLogger $audit): JsonResponse
    {
        return $this->setStatus($membership, 'active', 'user.activated', $request, $audit);
    }

    public function deactivate(string $membership, Request $request, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $model = $this->find($membership);

        // Never allow disabling the tenant owner.
        if ($model->user_id !== null && $model->user_id === $context->tenant()?->owner_user_id) {
            return response()->json(['message' => __('users.cannot_disable_owner')], 422);
        }

        return $this->setStatus($membership, 'disabled', 'user.deactivated', $request, $audit);
    }

    private function setStatus(string $membership, string $status, string $action, Request $request, AuditLogger $audit): JsonResponse
    {
        $model = $this->find($membership);
        $model->status = $status;
        $model->save();

        $audit->log($action, [
            'actor' => $request->user(),
            'subject' => $model,
            'metadata' => ['status' => $status],
        ]);

        return response()->json(['membership_id' => $model->id, 'status' => $model->status]);
    }

    /** Global scope + RLS guarantee this only finds the active tenant's rows. */
    private function find(string $membership): TenantMembership
    {
        return TenantMembership::query()->findOrFail($membership);
    }
}
