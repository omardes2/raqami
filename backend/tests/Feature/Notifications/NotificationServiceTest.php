<?php

namespace Tests\Feature\Notifications;

use App\Modules\Employees\Models\Employee;
use App\Modules\Identity\Models\TenantMembership;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Services\NotificationResult;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Sprint 8B Phase 1 — NotificationService: active-tenant-membership recipient rule
 * (User != Employee), writer-context persistence, DB dedupe, and the post-commit
 * invariant. Row existence is verified by reading under the recipient's own user
 * GUC (recipient-aware RLS), never by bypassing it.
 */
class NotificationServiceTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function service(): NotificationService
    {
        return app(NotificationService::class);
    }

    /** Count a recipient's rows under their own recipient RLS context. */
    private function inboxCount(Tenant $tenant, string $userId): int
    {
        $ctx = app(TenantContext::class);
        $ctx->setUser($userId);
        $ctx->setTenant($tenant);
        try {
            return (int) DB::table('notifications')->where('recipient_user_id', $userId)->count();
        } finally {
            $ctx->clear();
        }
    }

    private function notify(Tenant $tenant, string $recipientId, array $opts = []): NotificationResult
    {
        return $this->withinTenant($tenant, fn () => $this->service()->notify(
            $recipientId,
            $opts['type'] ?? 'test.event',
            ['key' => 'notifications.test.title', 'params' => ['x' => 1]],
            $opts,
        ));
    }

    public function test_active_member_recipient_is_notified(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $member = $this->memberWithRole($tenant, 'employee');

        $this->assertSame(NotificationResult::Created, $this->notify($tenant, (string) $member->getKey()));
        $this->assertSame(1, $this->inboxCount($tenant, (string) $member->getKey()));
    }

    public function test_recipient_without_membership_is_skipped(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $stranger = User::factory()->create(); // exists globally, no membership here

        $this->assertSame(NotificationResult::Skipped, $this->notify($tenant, (string) $stranger->getKey()));
        $this->assertSame(0, $this->inboxCount($tenant, (string) $stranger->getKey()));
    }

    public function test_disabled_and_invited_membership_are_skipped(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $disabled = User::factory()->create();
        $invited = User::factory()->create();
        $this->withinTenant($tenant, function () use ($disabled, $invited) {
            TenantMembership::create(['user_id' => $disabled->getKey(), 'status' => 'disabled']);
            TenantMembership::create(['user_id' => $invited->getKey(), 'status' => 'invited']);
        });

        $this->assertSame(NotificationResult::Skipped, $this->notify($tenant, (string) $disabled->getKey()));
        $this->assertSame(NotificationResult::Skipped, $this->notify($tenant, (string) $invited->getKey()));
    }

    public function test_cross_tenant_recipient_is_not_persisted(): void
    {
        [$ownerA, $tenantA] = $this->createCompanyWithOwner();
        [$ownerB, $tenantB] = $this->createCompanyWithOwner();
        $memberB = $this->memberWithRole($tenantB, 'employee'); // active only in B

        // Tenant A tries to notify a User who is active only in Tenant B.
        $this->assertSame(NotificationResult::Skipped, $this->notify($tenantA, (string) $memberB->getKey()));
        $this->assertSame(0, $this->inboxCount($tenantA, (string) $memberB->getKey()));
    }

    public function test_user_without_employee_can_receive(): void
    {
        // An admin User with no linked Employee is still a valid recipient.
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $admin = $this->memberWithRole($tenant, 'admin');
        $this->assertNull($this->withinTenant($tenant, fn () => Employee::query()->where('user_id', $admin->getKey())->value('id')));

        $this->assertSame(NotificationResult::Created, $this->notify($tenant, (string) $admin->getKey()));
        $this->assertSame(1, $this->inboxCount($tenant, (string) $admin->getKey()));
    }

    public function test_employee_linked_user_receives_and_unlinked_has_no_recipient(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $member = $this->memberWithRole($tenant, 'employee');
        // Employee WITH a linked User (active member) → recipient resolves + notified.
        $withUser = $this->withinTenant($tenant, fn () => Employee::factory()->create([
            'employee_number' => 'E-U', 'user_id' => $member->getKey(), 'employment_status' => 'active',
        ]));
        // Employee WITHOUT a User → no recipient user id exists at all.
        $noUser = $this->withinTenant($tenant, fn () => Employee::factory()->create([
            'employee_number' => 'E-N', 'user_id' => null, 'employment_status' => 'active',
        ]));

        $this->assertNotNull($withUser->user_id);
        $this->assertNull($noUser->user_id);
        $this->assertSame(NotificationResult::Created, $this->notify($tenant, (string) $member->getKey()));
    }

    public function test_dedupe_same_key_yields_one_row(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $member = $this->memberWithRole($tenant, 'employee');
        $opts = ['dedupe_key' => 'leave.approved:LR1:1'];

        $this->assertSame(NotificationResult::Created, $this->notify($tenant, (string) $member->getKey(), $opts));
        $this->assertSame(NotificationResult::Duplicate, $this->notify($tenant, (string) $member->getKey(), $opts));
        $this->assertSame(1, $this->inboxCount($tenant, (string) $member->getKey()));
    }

    public function test_dedupe_is_per_recipient(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $a = $this->memberWithRole($tenant, 'employee');
        $b = $this->memberWithRole($tenant, 'employee');
        $opts = ['dedupe_key' => 'task.assigned:ACT1'];

        $this->assertSame(NotificationResult::Created, $this->notify($tenant, (string) $a->getKey(), $opts));
        $this->assertSame(NotificationResult::Created, $this->notify($tenant, (string) $b->getKey(), $opts));
        $this->assertSame(1, $this->inboxCount($tenant, (string) $a->getKey()));
        $this->assertSame(1, $this->inboxCount($tenant, (string) $b->getKey()));
    }

    public function test_null_dedupe_allows_multiple_rows(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $member = $this->memberWithRole($tenant, 'employee');

        $this->assertSame(NotificationResult::Created, $this->notify($tenant, (string) $member->getKey()));
        $this->assertSame(NotificationResult::Created, $this->notify($tenant, (string) $member->getKey()));
        $this->assertSame(2, $this->inboxCount($tenant, (string) $member->getKey()));
    }

    public function test_payload_requires_key(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $member = $this->memberWithRole($tenant, 'employee');

        $this->expectException(\InvalidArgumentException::class);
        $this->withinTenant($tenant, fn () => $this->service()->notify((string) $member->getKey(), 'test.event', ['params' => []]));
    }
}
