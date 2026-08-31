<?php

namespace Database\Seeders;

use App\Modules\Platform\Models\PlatformAdmin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds a local development Super Admin. Credentials come from env with safe
 * local defaults and are NEVER committed. Do not use these in any real
 * environment.
 */
class PlatformAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('PLATFORM_ADMIN_EMAIL', 'superadmin@raqmidawam.test');

        PlatformAdmin::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => env('PLATFORM_ADMIN_NAME', 'Platform Super Admin'),
                'password' => Hash::make(env('PLATFORM_ADMIN_PASSWORD', 'password')),
                'status' => 'active',
                'email_verified_at' => now(),
            ],
        );
    }
}
