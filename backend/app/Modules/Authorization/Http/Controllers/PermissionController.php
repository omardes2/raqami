<?php

namespace App\Modules\Authorization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Authorization\Models\Permission;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    /** The global permission catalog grouped by module. */
    public function index(): JsonResponse
    {
        $grouped = Permission::query()->orderBy('module')->orderBy('key')->get()
            ->groupBy('module')
            ->map(fn ($items) => $items->map(fn (Permission $p) => [
                'key' => $p->key,
                'description' => $p->description,
            ])->values());

        return response()->json(['data' => $grouped]);
    }
}
