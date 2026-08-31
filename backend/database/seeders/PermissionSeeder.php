<?php

namespace Database\Seeders;

use App\Modules\Authorization\Services\PermissionSync;
use Illuminate\Database\Seeder;

/** Seeds the global Sprint 0 permission catalog. */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionSync::class)->sync();
    }
}
