<?php

namespace App\Modules\Authorization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Authorization\Models\Role;
use App\Modules\Authorization\Services\RoleAssignmentService;
use App\Modules\Authorization\Support\PermissionCatalog;
use App\Modules\Identity\Models\TenantMembership;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoleAssignmentController extends Controller
{
    /** Assign a role to a user (within an organizational scope). */
    public function store(Request $request, RoleAssignmentService $assignments): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'string'],
            'role_id' => ['required', 'string'],
            'scope_type' => ['sometimes', Rule::in(PermissionCatalog::SCOPE_TYPES)],
            'scope_id' => ['sometimes', 'nullable', 'string'],
        ]);

        // The target user MUST be a member of the active tenant (RLS-scoped).
        $isMember = TenantMembership::query()->where('user_id', $data['user_id'])->exists();
        if (! $isMember) {
            return response()->json(['message' => __('users.not_a_member')], 422);
        }

        // Role must belong to the active tenant (RLS-scoped lookup).
        $role = Role::query()->findOrFail($data['role_id']);
        $user = User::query()->findOrFail($data['user_id']);

        $assignment = $assignments->assign(
            $user,
            $role,
            $data['scope_type'] ?? 'company',
            $data['scope_id'] ?? null,
            $request->user(),
        );

        return response()->json([
            'id' => $assignment->id,
            'user_id' => $assignment->user_id,
            'role_id' => $assignment->role_id,
            'scope_type' => $assignment->scope_type,
            'scope_id' => $assignment->scope_id,
        ], 201);
    }
}
