<?php

namespace Tests\Feature\Mobile;

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Sprint 10 — mobile (Bearer-token) authentication surface (ADR-004).
 *
 * A stateless personal access token replaces the SPA session cookie; every
 * downstream tenancy/RLS/permission guarantee is unchanged and is asserted here
 * through the token, most importantly that a token cannot act on a company the
 * user does not belong to.
 */
class MobileAuthTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_login_issues_a_bearer_token_and_lists_active_memberships(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $res = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $owner->email,
            'password' => 'password',
            'device_name' => 'iPhone 15',
        ])->assertCreated();

        $res->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.email', $owner->email)
            ->assertJsonPath('memberships.0.tenant_id', $tenant->id);

        $this->assertNotEmpty($res->json('token'));
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        [$owner] = $this->createCompanyWithOwner();

        $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $owner->email,
            'password' => 'wrong-password',
            'device_name' => 'iPhone 15',
        ])->assertStatus(422);
    }

    public function test_login_requires_a_device_name(): void
    {
        [$owner] = $this->createCompanyWithOwner();

        $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $owner->email,
            'password' => 'password',
        ])->assertStatus(422)->assertJsonValidationErrors('device_name');
    }

    public function test_bearer_token_authenticates_the_tenant_api(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $token = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $owner->email, 'password' => 'password', 'device_name' => 'Pixel',
        ])->json('token');

        // Session resolves the active tenant and returns backend-authoritative
        // permissions/roles for that company.
        $this->withHeaders($this->bearer($token) + $this->tenantHeaders($tenant))
            ->getJson('/api/mobile/v1/auth/session')
            ->assertOk()
            ->assertJsonPath('active_tenant.id', $tenant->id)
            ->assertJsonPath('memberships.0.tenant_id', $tenant->id);

        // The same token works against the existing SPA-shared endpoints.
        $this->withHeaders($this->bearer($token) + $this->tenantHeaders($tenant))
            ->getJson('/api/company')
            ->assertOk()
            ->assertJsonPath('id', $tenant->id);
    }

    public function test_token_carries_the_mobile_ability(): void
    {
        [$owner] = $this->createCompanyWithOwner();
        $token = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $owner->email, 'password' => 'password', 'device_name' => 'Watch',
        ])->json('token');

        $model = PersonalAccessToken::findToken($token);
        $this->assertNotNull($model);
        $this->assertTrue($model->can('mobile'));
    }

    public function test_logout_revokes_the_current_token(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $token = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $owner->email, 'password' => 'password', 'device_name' => 'iPad',
        ])->json('token');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/mobile/v1/auth/logout')
            ->assertOk();

        // Force the auth guard to re-resolve (each production request is fresh;
        // within one test the guard caches the previously resolved user).
        $this->app['auth']->forgetGuards();

        // The revoked token no longer authenticates.
        $this->withHeaders($this->bearer($token) + $this->tenantHeaders($tenant))
            ->getJson('/api/mobile/v1/auth/session')
            ->assertUnauthorized();
    }

    public function test_relogin_on_the_same_device_supersedes_the_old_token(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $first = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $owner->email, 'password' => 'password', 'device_name' => 'SharedName',
        ])->json('token');

        $second = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $owner->email, 'password' => 'password', 'device_name' => 'SharedName',
        ])->json('token');

        $this->assertNotSame($first, $second);

        // Old token is dead; new token works.
        $this->withHeaders($this->bearer($first))
            ->getJson('/api/mobile/v1/auth/session')->assertUnauthorized();
        $this->withHeaders($this->bearer($second) + $this->tenantHeaders($tenant))
            ->getJson('/api/mobile/v1/auth/session')->assertOk();
    }

    public function test_token_cannot_act_on_a_company_the_user_does_not_belong_to(): void
    {
        [$ownerA, $tenantA] = $this->createCompanyWithOwner(['name' => 'Alpha']);
        [, $tenantB] = $this->createCompanyWithOwner(['name' => 'Beta']);

        $token = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $ownerA->email, 'password' => 'password', 'device_name' => 'Device',
        ])->json('token');

        // Selecting a foreign tenant resolves NO context (never a silent
        // fallback to a tenant they do belong to).
        $this->withHeaders($this->bearer($token) + $this->tenantHeaders($tenantB))
            ->getJson('/api/mobile/v1/auth/session')
            ->assertOk()
            ->assertJsonPath('active_tenant', null);

        // A tenant-required endpoint is refused outright for the foreign tenant.
        $this->withHeaders($this->bearer($token) + $this->tenantHeaders($tenantB))
            ->getJson('/api/company')
            ->assertStatus(409);

        // Login memberships never leak the foreign company.
        $memberships = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $ownerA->email, 'password' => 'password', 'device_name' => 'Device2',
        ])->json('memberships');

        $ids = collect($memberships)->pluck('tenant_id')->all();
        $this->assertContains($tenantA->id, $ids);
        $this->assertNotContains($tenantB->id, $ids);
    }
}
