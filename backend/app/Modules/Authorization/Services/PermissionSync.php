<?php

namespace App\Modules\Authorization\Services;

use App\Modules\Authorization\Models\Permission;
use App\Modules\Authorization\Support\PermissionCatalog;

/** Upserts the global permission catalog. Idempotent. */
class PermissionSync
{
    public function sync(): void
    {
        foreach (PermissionCatalog::PERMISSIONS as $key => [$module, $description]) {
            Permission::query()->updateOrCreate(
                ['key' => $key],
                ['module' => $module, 'description' => $description],
            );
        }
    }
}
