<?php

namespace Tests\Feature\Notifications;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Sprint 8B Phase 1 — personal inbox API: ownership, IDOR-safe read, read-all
 * scoping, unread count, and response privacy. Rows are seeded only through
 * NotificationService (the sole writer).
 */
class NotificationApiTest extends TestCase
{
    use InteractsWithTenancy;
    use RefreshDatabase;

    private function seedNotes(Tenant $tenant, User $recipient, int $count, ?string $dedupePrefix = null): void
    {
        $this->withinTenant($tenant, function () use ($recipient, $count, $dedupePrefix) {
            $service = app(NotificationService::class);
            for ($i = 0; $i < $count; $i++) {
                $service->notify((string) $recipient->getKey(), 'test.event',
                    ['key' => 'notifications.test.title', 'params' => ['n' => $i]],
                    $dedupePrefix ? ['dedupe_key' => "{$dedupePrefix}:{$i}"] : []);
            }
        });
    }

    public function test_index_lists_only_own_notifications(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $a = $this->memberWithRole($tenant, 'employee');
        $b = $this->memberWithRole($tenant, 'employee');
        $this->seedNotes($tenant, $a, 2);
        $this->seedNotes($tenant, $b, 3);

        $body = $this->actingAs($a)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/me/notifications')->assertOk()->json();
        $this->assertCount(2, $body['data']);
    }

    public function test_unread_filter_and_count(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $a = $this->memberWithRole($tenant, 'employee');
        $this->seedNotes($tenant, $a, 3);

        $this->actingAs($a)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/me/notifications/unread-count')->assertOk()
            ->assertJsonPath('data.unread_count', 3);

        $unread = $this->actingAs($a)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/me/notifications?unread=1')->assertOk()->json();
        $this->assertCount(3, $unread['data']);
    }

    public function test_mark_one_read_is_idempotent_and_scoped(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $a = $this->memberWithRole($tenant, 'employee');
        $this->seedNotes($tenant, $a, 1);
        $id = $this->actingAs($a)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/me/notifications')->json('data.0.id');

        $this->actingAs($a)->withHeaders($this->tenantHeaders($tenant))
            ->patchJson("/api/me/notifications/{$id}/read")->assertOk()
            ->assertJsonPath('data.read_at', fn ($v) => $v !== null);
        // Idempotent.
        $this->actingAs($a)->withHeaders($this->tenantHeaders($tenant))
            ->patchJson("/api/me/notifications/{$id}/read")->assertOk();
        $this->actingAs($a)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/me/notifications/unread-count')->assertJsonPath('data.unread_count', 0);
    }

    public function test_mark_read_foreign_notification_returns_404(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $a = $this->memberWithRole($tenant, 'employee');
        $b = $this->memberWithRole($tenant, 'employee');
        $this->seedNotes($tenant, $b, 1);
        $bId = $this->actingAs($b)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/me/notifications')->json('data.0.id');

        // Same-tenant User A knows B's id → 404 (not 403), no existence leak.
        $this->actingAs($a)->withHeaders($this->tenantHeaders($tenant))
            ->patchJson("/api/me/notifications/{$bId}/read")->assertStatus(404);
        // B's row remains unread.
        $this->actingAs($b)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/me/notifications/unread-count')->assertJsonPath('data.unread_count', 1);
    }

    public function test_read_all_marks_only_callers_unread(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $a = $this->memberWithRole($tenant, 'employee');
        $b = $this->memberWithRole($tenant, 'employee');
        $this->seedNotes($tenant, $a, 2);
        $this->seedNotes($tenant, $b, 2);

        $this->actingAs($a)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/me/notifications/read-all')->assertOk()
            ->assertJsonPath('data.updated', 2);
        $this->actingAs($a)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/me/notifications/unread-count')->assertJsonPath('data.unread_count', 0);
        // B untouched.
        $this->actingAs($b)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/me/notifications/unread-count')->assertJsonPath('data.unread_count', 2);
    }

    public function test_resource_hides_internal_fields(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $a = $this->memberWithRole($tenant, 'employee');
        $this->seedNotes($tenant, $a, 1);

        $raw = $this->actingAs($a)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/me/notifications')->getContent();
        foreach (['tenant_id', 'recipient_user_id', 'dedupe_key'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $raw, "resource must not expose {$forbidden}");
        }
    }

    public function test_no_create_or_delete_routes(): void
    {
        [$owner, $tenant] = $this->createCompanyWithOwner();
        $a = $this->memberWithRole($tenant, 'employee');
        $this->seedNotes($tenant, $a, 1);
        $id = $this->actingAs($a)->withHeaders($this->tenantHeaders($tenant))
            ->getJson('/api/me/notifications')->json('data.0.id');

        // No public create endpoint.
        $this->actingAs($a)->withHeaders($this->tenantHeaders($tenant))
            ->postJson('/api/notifications', ['type' => 'x'])->assertStatus(404);
        // No delete endpoint — the route does not exist at all (404, no matching route).
        $this->actingAs($a)->withHeaders($this->tenantHeaders($tenant))
            ->deleteJson("/api/me/notifications/{$id}")->assertStatus(404);
    }

    public function test_cross_tenant_notification_not_visible(): void
    {
        [$ownerA, $tenantA] = $this->createCompanyWithOwner();
        [$ownerB, $tenantB] = $this->createCompanyWithOwner();
        $a = $this->memberWithRole($tenantA, 'employee');
        $this->seedNotes($tenantA, $a, 2);

        // ownerB in tenantB sees nothing from tenantA.
        $this->actingAs($ownerB)->withHeaders($this->tenantHeaders($tenantB))
            ->getJson('/api/me/notifications')->assertOk()->assertJsonCount(0, 'data');
    }
}
