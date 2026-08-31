<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    public function forgot(Request $request, AuditLogger $audit): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $status = Password::sendResetLink($request->only('email'));

        $audit->log('auth.password_reset_requested', [
            'actor_type' => 'system',
            'metadata' => ['email' => $request->string('email')],
        ]);

        // Always return a neutral response to avoid user enumeration.
        return response()->json(['message' => __($status)]);
    }

    public function reset(Request $request, AuditLogger $audit): JsonResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password) {
                // The 'hashed' cast on the User model hashes this on save.
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();
            }
        );

        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        $audit->log('auth.password_reset', [
            'actor_type' => 'system',
            'metadata' => ['email' => $request->string('email')],
        ]);

        return response()->json(['message' => __($status)]);
    }
}
