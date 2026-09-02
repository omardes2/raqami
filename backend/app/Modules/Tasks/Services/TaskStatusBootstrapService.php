<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Tasks\Enums\TaskStatusCategory;
use App\Modules\Tasks\Models\TaskStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantContext;

/**
 * Seeds the default task status catalog for a tenant, idempotently. Identity is
 * the immutable `bootstrap_key` (NOT the tenant-editable `code`), so a tenant may
 * rename/recolor/reorder a default without a re-run duplicating it (§9). Used at
 * onboarding (new tenants) and by a backfill command (existing tenants). The
 * `todo` status is the seeded default.
 */
class TaskStatusBootstrapService
{
    /** @var array<int, array{key:string, name:string, code:string, category:TaskStatusCategory, color:string, sort:int, default:bool}> */
    private const DEFAULTS = [
        ['key' => 'todo', 'name' => 'To Do', 'code' => 'todo', 'category' => TaskStatusCategory::Todo, 'color' => '#6B7280', 'sort' => 10, 'default' => true],
        ['key' => 'in_progress', 'name' => 'In Progress', 'code' => 'in_progress', 'category' => TaskStatusCategory::InProgress, 'color' => '#2563EB', 'sort' => 20, 'default' => false],
        ['key' => 'blocked', 'name' => 'Blocked', 'code' => 'blocked', 'category' => TaskStatusCategory::Blocked, 'color' => '#D97706', 'sort' => 30, 'default' => false],
        ['key' => 'done', 'name' => 'Done', 'code' => 'done', 'category' => TaskStatusCategory::Done, 'color' => '#16A34A', 'sort' => 40, 'default' => false],
        ['key' => 'cancelled', 'name' => 'Cancelled', 'code' => 'cancelled', 'category' => TaskStatusCategory::Cancelled, 'color' => '#DC2626', 'sort' => 50, 'default' => false],
    ];

    public function __construct(private readonly TenantContext $context) {}

    /** Bootstrap default statuses for the given tenant (idempotent by bootstrap_key). */
    public function bootstrap(Tenant $tenant): void
    {
        $this->context->runAs($tenant, fn () => $this->seed());
    }

    /** Bootstrap within the already-active tenant context (idempotent). */
    public function seed(): void
    {
        foreach (self::DEFAULTS as $def) {
            $existing = TaskStatus::query()->where('bootstrap_key', $def['key'])->first();
            if ($existing !== null) {
                continue; // tenant customizations preserved; never overwritten
            }

            TaskStatus::query()->create([
                'name' => $def['name'],
                'code' => $this->uniqueCode($def['code']),
                'bootstrap_key' => $def['key'],
                'category' => $def['category'],
                'color' => $def['color'],
                'sort_order' => $def['sort'],
                'is_default' => $def['default'] && ! $this->hasActiveDefault(),
                'active' => true,
            ]);
        }

        // Guarantee at least one active default (partial unique guarantees at most one).
        if (! $this->hasActiveDefault()) {
            $fallback = TaskStatus::query()->where('active', true)->orderBy('sort_order')->first();
            $fallback?->forceFill(['is_default' => true])->save();
        }
    }

    private function hasActiveDefault(): bool
    {
        return TaskStatus::query()->where('is_default', true)->where('active', true)->exists();
    }

    /** Avoid colliding with a tenant-customized code that already occupies the slot. */
    private function uniqueCode(string $base): string
    {
        $code = $base;
        $i = 1;
        while (TaskStatus::query()->where('code', $code)->exists()) {
            $code = $base.'_'.(++$i);
        }

        return $code;
    }
}
