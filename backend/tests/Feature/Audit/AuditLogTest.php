<?php

namespace Tests\Feature\Audit;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    public function test_onboarding_generates_audit_events(): void
    {
        [, $tenant] = $this->createCompanyWithOwner();

        $actions = $this->withinTenant($tenant, fn () => AuditLog::query()->pluck('action')->all());

        $this->assertContains('tenant.created', $actions);
        $this->assertContains('membership.created', $actions);
        $this->assertContains('role.assigned', $actions);
    }

    public function test_login_generates_an_audit_event(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123!')]);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Password123!'])->assertOk();

        // Read audit across tenants via the audited platform read context.
        $exists = app(TenantContext::class)->runAsPlatform(
            fn () => AuditLog::query()->where('action', 'auth.login')->exists()
        );
        $this->assertTrue($exists);
    }

    public function test_audit_metadata_never_stores_secrets(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $log = $this->withinTenant($tenant, fn () => app(AuditLogger::class)->log('test.event', [
            'actor' => $owner,
            'metadata' => ['password' => 'super-secret', 'note' => 'ok'],
        ]));

        $this->assertSame('[redacted]', $log->metadata['password']);
        $this->assertSame('ok', $log->metadata['note']);
    }

    public function test_audit_entries_cannot_be_updated_or_deleted_by_the_app(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();

        $this->withinTenant($tenant, function () use ($owner) {
            $log = app(AuditLogger::class)->log('test.event', ['actor' => $owner]);

            // RLS exposes no UPDATE/DELETE path: both affect zero rows.
            $updated = AuditLog::query()->where('id', $log->id)->update(['action' => 'tampered']);
            $deleted = AuditLog::query()->where('id', $log->id)->delete();

            $this->assertSame(0, $updated);
            $this->assertSame(0, $deleted);
            $this->assertSame('test.event', AuditLog::query()->find($log->id)->action);
        });
    }
}
