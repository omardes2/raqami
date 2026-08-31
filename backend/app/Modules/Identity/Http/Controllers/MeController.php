<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Localization\Services\LocaleService;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function update(Request $request, LocaleService $locales, AuditLogger $audit): UserResource
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'locale' => ['sometimes', 'in:'.implode(',', $locales->supported())],
            'timezone' => ['sometimes', 'string', 'max:64'],
        ]);

        $user = $request->user();
        $user->fill($validated)->save();

        $audit->log('user.profile_updated', [
            'actor' => $user,
            'subject' => $user,
            'metadata' => ['fields' => array_keys($validated)],
        ]);

        return new UserResource($user);
    }
}
