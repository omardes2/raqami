<?php

namespace App\Modules\Onboarding\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Authorization\Services\RoleAssignmentService;
use App\Modules\Authorization\Services\RoleProvisioner;
use App\Modules\Identity\Models\TenantMembership;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Minimal company onboarding (Sprint 0). A registered user creates their
 * company, becomes the Owner, and enters the app. Designed so the Billing
 * sprint can insert plan selection/payment WITHOUT rewriting onboarding:
 * provisioning is a discrete step separate from any future paywall.
 *
 * NO subscription/payment is created here.
 */
class CompanyOnboardingService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly RoleProvisioner $roleProvisioner,
        private readonly RoleAssignmentService $assignments,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name:string, legal_name?:string, slug?:string,
     *     country_code?:string, timezone?:string, default_locale?:string,
     *     default_currency?:string}  $data
     */
    public function createCompany(User $owner, array $data): Tenant
    {
        return DB::transaction(function () use ($owner, $data) {
            // 1. Tenant registry row (no RLS on tenants).
            $tenant = Tenant::query()->create([
                'name' => $data['name'],
                'legal_name' => $data['legal_name'] ?? null,
                'slug' => $this->uniqueSlug($data['slug'] ?? $data['name']),
                'country_code' => $data['country_code'] ?? null,
                'timezone' => $data['timezone'] ?? 'UTC',
                'default_locale' => $data['default_locale'] ?? $owner->locale ?? 'en',
                'default_currency' => $data['default_currency'] ?? 'USD',
                'status' => 'active',
                'owner_user_id' => $owner->getKey(),
            ]);

            // 2. Everything below is tenant-owned — run inside tenant context.
            return $this->context->runAs($tenant, function () use ($tenant, $owner) {
                $roles = $this->roleProvisioner->provisionDefaults($tenant);

                $membership = TenantMembership::query()->create([
                    'user_id' => $owner->getKey(),
                    'status' => 'active',
                    'invitation_accepted_at' => now(),
                ]);

                // Owner role at company scope. Owner holds every permission
                // inside its tenant but does NOT bypass tenant isolation.
                $this->assignments->assign($owner, $roles['owner'], 'company', null, $owner);

                $this->audit->log('tenant.created', [
                    'actor' => $owner,
                    'subject' => $tenant,
                    'metadata' => ['name' => $tenant->name, 'slug' => $tenant->slug],
                ]);
                $this->audit->log('membership.created', [
                    'actor' => $owner,
                    'subject' => $membership,
                    'metadata' => ['role' => 'owner', 'reason' => 'onboarding_owner'],
                ]);

                return $tenant;
            });
        });
    }

    private function uniqueSlug(string $base): string
    {
        $slug = Str::slug($base) ?: 'company';
        $candidate = $slug;
        $i = 1;

        while (Tenant::query()->withTrashed()->where('slug', $candidate)->exists()) {
            $candidate = $slug.'-'.(++$i);
        }

        return $candidate;
    }
}
