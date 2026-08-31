<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Authentication for the Super Admin portal — a SEPARATE guard from tenants. */
class PlatformAuthController extends Controller
{
    public function login(Request $request, AuditLogger $audit): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = 'platform|'.Str::lower($request->string('email')).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        if (! Auth::guard('platform')->attempt($request->only('email', 'password'))) {
            RateLimiter::hit($key);
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($key);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $admin = Auth::guard('platform')->user();
        $audit->log('platform.login', ['actor' => $admin, 'subject' => $admin, 'tenant_id' => null]);

        return response()->json($this->adminPayload($admin));
    }

    public function logout(Request $request, AuditLogger $audit): JsonResponse
    {
        $admin = Auth::guard('platform')->user();
        if ($admin !== null) {
            $audit->log('platform.logout', ['actor' => $admin, 'subject' => $admin, 'tenant_id' => null]);
        }

        Auth::guard('platform')->logout();

        return response()->json(['message' => __('auth.logged_out')]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->adminPayload(Auth::guard('platform')->user()));
    }

    private function adminPayload($admin): array
    {
        return [
            'id' => $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
            'is_platform_admin' => true,
        ];
    }
}
