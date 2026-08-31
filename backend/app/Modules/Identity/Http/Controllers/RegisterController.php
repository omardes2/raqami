<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Http\Requests\RegisterRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class RegisterController extends Controller
{
    public function store(RegisterRequest $request, AuditLogger $audit): JsonResponse
    {
        $user = User::create([
            'name' => $request->string('name'),
            'email' => $request->string('email'),
            'password' => $request->string('password'),
            'locale' => $request->input('locale', config('app.locale')),
        ]);

        event(new Registered($user));           // sends email verification
        Auth::guard('web')->login($user);
        // Session only exists for the stateful SPA; token/mobile auth has none.
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $audit->log('auth.registered', ['actor' => $user, 'subject' => $user]);

        return (new UserResource($user))->response()->setStatusCode(201);
    }
}
