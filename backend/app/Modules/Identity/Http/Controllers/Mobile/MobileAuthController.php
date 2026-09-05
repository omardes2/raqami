<?php

namespace App\Modules\Identity\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Http\Requests\MobileLoginRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\TenantMembership;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Mobile (Bearer-token) authentication surface (ADR-004, Sprint 10).
 *
 * The mobile client authenticates once here to obtain a stateless Sanctum
 * personal access token, then consumes the SAME versioned API as the SPA by
 * sending `Authorization: Bearer <token>` plus `X-Tenant-Id` to select the
 * active company. Tenancy, RLS, and permission checks are unchanged — the token
 * only replaces the SPA session cookie as the identity carrier.
 *
 * Tokens carry the 'mobile' ability so they can be told apart from any future
 * token class. Login never resolves a tenant (the user may belong to several);
 * it returns the user's active memberships so the app can pick one.
 */
class MobileAuthController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function login(MobileLoginRequest $request, AuditLogger $audit): JsonResponse
    {
        $user = $request->authenticateStateless();

        // A fresh login supersedes prior tokens for the same device name, so a
        // lost/reinstalled device does not accumulate live credentials.
        $deviceName = (string) $request->string('device_name');
        $user->tokens()->where('name', $deviceName)->delete();

        $token = $user->createToken($deviceName, ['mobile'])->plainTextToken;

        $audit->log('auth.login', [
            'actor' => $user,
            'subject' => $user,
            'metadata' => ['channel' => 'mobile', 'device' => $deviceName],
        ]);

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale,
                'timezone' => $user->timezone,
            ],
            'memberships' => $this->membershipsFor($user),
        ], 201);
    }

    /**
     * Current session for the active tenant (selected via X-Tenant-Id). Returns
     * the full user resource (permissions/roles for the active tenant) plus the
     * user's memberships so the app can offer a company switcher.
     */
    public function session(Request $request): JsonResponse
    {
        $user = $request->user();

        // Flatten the (unwrapped) user resource and attach memberships as a
        // sibling so the mobile client gets one predictable top-level object.
        $payload = (new UserResource($user))->resolve($request);
        $payload['memberships'] = $this->membershipsFor($user);

        return response()->json($payload);
    }

    /** Revoke the token that authenticated THIS request (single-device logout). */
    public function logout(Request $request, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        if ($user !== null) {
            $audit->log('auth.logout', [
                'actor' => $user,
                'subject' => $user,
                'metadata' => ['channel' => 'mobile'],
            ]);
        }

        return response()->json(['message' => __('auth.logged_out')]);
    }

    /**
     * The user's active tenant memberships. Read under the user's own identity
     * (own-membership RLS policy) without a tenant context, since a user may
     * belong to several companies and none is selected yet at login.
     *
     * @return array<int, array<string, mixed>>
     */
    private function membershipsFor(User $user): array
    {
        // Read under the user's own identity so the own-membership RLS policy
        // applies, then restore the prior user context (login runs outside the
        // tenant middleware, so nothing else resets it).
        $previousUser = $this->context->userId();
        $this->context->setUser($user->getKey());

        try {
            $memberships = TenantMembership::query()
                ->withoutGlobalScope(TenantScope::class)
                ->where('user_id', $user->getKey())
                ->where('status', 'active')
                ->orderBy('created_at')
                ->get();

            $tenants = Tenant::query()
                ->whereIn('id', $memberships->pluck('tenant_id')->all())
                ->get()
                ->keyBy('id');

            return $memberships
                ->map(function (TenantMembership $m) use ($tenants): ?array {
                    $tenant = $tenants->get($m->tenant_id);
                    if ($tenant === null) {
                        return null;
                    }

                    return [
                        'tenant_id' => $tenant->id,
                        'name' => $tenant->name,
                        'slug' => $tenant->slug,
                        'default_locale' => $tenant->default_locale,
                        'status' => $tenant->status,
                    ];
                })
                ->filter()
                ->values()
                ->all();
        } finally {
            $this->context->setUser($previousUser);
        }
    }
}
