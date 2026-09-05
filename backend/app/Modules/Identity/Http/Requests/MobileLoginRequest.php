<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Credential validation for mobile (Bearer-token) login. Unlike the SPA login
 * this issues a stateless Sanctum personal access token (ADR-004) rather than a
 * session cookie, so it never touches the session. Brute-force protection is
 * identical to the SPA login (per email|ip, 5 attempts).
 */
class MobileLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            // Names the token so a user can identify/revoke a specific device.
            'device_name' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * Verify credentials without establishing a session. Returns the
     * authenticated user on success; throws a rate-limited validation error
     * otherwise.
     */
    public function authenticateStateless(): User
    {
        $this->ensureIsNotRateLimited();

        /** @var User|null $user */
        $user = User::query()
            ->where('email', $this->string('email'))
            ->first();

        if ($user === null || ! Hash::check((string) $this->string('password'), (string) $user->password)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        return $user;
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => RateLimiter::availableIn($this->throttleKey()),
            ]),
        ]);
    }

    public function throttleKey(): string
    {
        return 'mobile|'.Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
