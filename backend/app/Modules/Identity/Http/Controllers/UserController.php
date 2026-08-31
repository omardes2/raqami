<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Authorization\Services\AccessService;
use App\Modules\Identity\Models\TenantMembership;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    /** List members of the ACTIVE tenant. Tenant-scoped by RLS + global scope. */
    public function index(AccessService $access): JsonResponse
    {
        $members = TenantMembership::query()
            ->with('user')
            ->orderBy('created_at')
            ->get()
            ->map(fn (TenantMembership $m) => [
                'membership_id' => $m->id,
                'status' => $m->status,
                'invited_email' => $m->invited_email,
                'user' => $m->user ? [
                    'id' => $m->user->id,
                    'name' => $m->user->name,
                    'email' => $m->user->email,
                    'roles' => $m->user ? $access->roleSlugsFor($m->user)->all() : [],
                ] : null,
            ]);

        return response()->json(['data' => $members]);
    }
}
