<?php

namespace App\Modules\Authorization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Authorization\Models\Role;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    /** Roles of the active tenant with their permissions. */
    public function index(): JsonResponse
    {
        $roles = Role::query()->with('permissions')->orderBy('name')->get()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'is_system' => $role->is_system,
                'permissions' => $role->permissions->pluck('key')->all(),
            ]);

        return response()->json(['data' => $roles]);
    }
}
