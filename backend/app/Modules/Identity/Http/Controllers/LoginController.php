<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    public function store(LoginRequest $request, AuditLogger $audit): JsonResponse
    {
        $request->authenticate();
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $user = $request->user();
        $audit->log('auth.login', ['actor' => $user, 'subject' => $user]);

        return (new UserResource($user))->response();
    }

    public function destroy(Request $request, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        if ($user !== null) {
            $audit->log('auth.logout', ['actor' => $user, 'subject' => $user]);
        }

        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => __('auth.logged_out')]);
    }
}
