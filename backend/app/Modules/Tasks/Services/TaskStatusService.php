<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Tasks\Enums\TaskStatusCategory;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskStatus;
use App\Modules\Tasks\Support\TaskStatusLock;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tenant task status catalog administration (permission: tasks.settings.manage —
 * enforced at the route). `category` is the immutable semantic once a status is
 * referenced by any task (Correction E / §10). Exactly one active default is kept
 * (DB partial unique guarantees at-most-one; this service guarantees at-least-one).
 * Statuses are deactivated, never hard-deleted, while referenced.
 */
class TaskStatusService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array{name:string, code:string, category:string, color?:?string, sort_order?:int, is_default?:bool}  $data
     */
    public function create(User $actor, array $data): TaskStatus
    {
        $category = TaskStatusCategory::from($data['category']);

        return DB::transaction(function () use ($actor, $data, $category) {
            $status = TaskStatus::query()->create([
                'name' => $data['name'],
                'code' => $data['code'],
                'bootstrap_key' => null, // custom statuses have no system identity
                'category' => $category,
                'color' => $data['color'] ?? null,
                'sort_order' => $data['sort_order'] ?? 0,
                'is_default' => false,
                'active' => true,
            ]);

            if (($data['is_default'] ?? false) === true) {
                $this->setDefault($actor, $status->fresh());
            }

            $this->audit->log('tasks.status_created', ['actor' => $actor, 'subject' => $status]);

            return $status->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, TaskStatus $status, array $data): TaskStatus
    {
        return DB::transaction(function () use ($actor, $status, $data) {
            $status = TaskStatus::query()->lockForUpdate()->findOrFail($status->getKey());

            if (array_key_exists('category', $data)) {
                $newCategory = TaskStatusCategory::from($data['category']);
                if ($newCategory !== $status->category && $this->isReferenced($status)) {
                    $this->fail(__('tasks.status_category_locked'));
                }
                $status->category = $newCategory;
            }
            foreach (['name', 'code', 'color', 'sort_order'] as $field) {
                if (array_key_exists($field, $data)) {
                    $status->{$field} = $data[$field];
                }
            }
            $status->save();

            if (($data['is_default'] ?? null) === true) {
                $this->setDefault($actor, $status->fresh());
            }

            $this->audit->log('tasks.status_updated', [
                'actor' => $actor, 'subject' => $status, 'metadata' => ['fields' => array_keys($data)],
            ]);

            return $status->fresh();
        });
    }

    /** Atomically move the active default to $status (clears the previous one first). */
    public function setDefault(User $actor, TaskStatus $status): TaskStatus
    {
        return DB::transaction(function () use ($actor, $status) {
            // Serialize concurrent default swaps for this tenant so the "one active
            // default" partial-unique index can never surface a raw error when two
            // admins set a default at the same instant (H3): the second waits, then
            // sees the first's committed default and simply moves it.
            TaskStatusLock::forDefault((string) $this->context->tenantId());

            $status = TaskStatus::query()->lockForUpdate()->findOrFail($status->getKey());
            if (! $status->active) {
                $this->fail(__('tasks.status_inactive'));
            }
            TaskStatus::query()->where('is_default', true)->where('active', true)
                ->whereKeyNot($status->getKey())->update(['is_default' => false]);
            $status->forceFill(['is_default' => true])->save();

            $this->audit->log('tasks.status_default_set', ['actor' => $actor, 'subject' => $status]);

            return $status->fresh();
        });
    }

    public function deactivate(User $actor, TaskStatus $status): TaskStatus
    {
        return DB::transaction(function () use ($actor, $status) {
            // Same tenant default lock: deactivating the default promotes a
            // replacement, which must not race a concurrent setDefault (H3).
            TaskStatusLock::forDefault((string) $this->context->tenantId());

            $status = TaskStatus::query()->lockForUpdate()->findOrFail($status->getKey());
            // Cannot remove the sole active default without a replacement.
            if ($status->is_default) {
                $replacement = TaskStatus::query()->where('active', true)->whereKeyNot($status->getKey())
                    ->orderBy('sort_order')->first();
                if ($replacement === null) {
                    $this->fail(__('tasks.status_default_required'));
                }
                $status->forceFill(['is_default' => false])->save();
                $replacement->forceFill(['is_default' => true])->save();
            }
            $status->forceFill(['active' => false])->save();

            $this->audit->log('tasks.status_deactivated', ['actor' => $actor, 'subject' => $status]);

            return $status->fresh();
        });
    }

    public function reactivate(User $actor, TaskStatus $status): TaskStatus
    {
        $status->forceFill(['active' => true])->save();
        $this->audit->log('tasks.status_reactivated', ['actor' => $actor, 'subject' => $status]);

        return $status->fresh();
    }

    /** @param array<int, string> $orderedIds */
    public function reorder(User $actor, array $orderedIds): void
    {
        DB::transaction(function () use ($orderedIds) {
            foreach (array_values($orderedIds) as $i => $id) {
                TaskStatus::query()->whereKey($id)->update(['sort_order' => ($i + 1) * 10]);
            }
        });
        $this->audit->log('tasks.status_reordered', ['actor' => $actor, 'metadata' => ['count' => count($orderedIds)]]);
    }

    private function isReferenced(TaskStatus $status): bool
    {
        return Task::query()->where('status_id', $status->getKey())->exists();
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['status' => [$message]]);
    }
}
