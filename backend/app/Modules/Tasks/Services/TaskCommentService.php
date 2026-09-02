<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\TenantMembership;
use App\Modules\Identity\Models\User;
use App\Modules\Tasks\Enums\TaskActivityType;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskComment;
use App\Modules\Tasks\Models\TaskCommentMention;
use App\Modules\Tasks\Support\TaskActivityRecorder;
use App\Modules\Tasks\Support\TaskAuthorizer;
use App\Modules\Tasks\Support\TaskVisibilityResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Task comments (Correction G) + @mentions (§25). Author identity is a User;
 * soft-delete only; optimistic version on edit/delete; creator-scoped idempotent
 * create. A mention is persisted only for a same-tenant user who already has task
 * visibility — a mention NEVER grants visibility. No notification transport.
 */
class TaskCommentService
{
    public function __construct(
        private readonly TaskAuthorizer $authorizer,
        private readonly TaskVisibilityResolver $visibility,
        private readonly TaskActivityRecorder $activity,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<int, string>  $mentionUserIds
     */
    public function create(User $actor, Task $task, string $body, array $mentionUserIds = [], ?string $clientRequestId = null): TaskComment
    {
        $fingerprint = $this->fingerprint(['task_id' => $task->getKey(), 'body' => $body]);

        if ($clientRequestId !== null && $clientRequestId !== '') {
            $existing = TaskComment::query()
                ->where('user_id', (string) $actor->getKey())
                ->where('client_request_id', $clientRequestId)
                ->first();
            if ($existing !== null) {
                if ((string) $existing->client_request_hash !== $fingerprint) {
                    throw new ConflictHttpException(__('tasks.idempotency_conflict'));
                }

                return $existing;
            }
        }

        $mentionIds = $this->validateMentions($task, $mentionUserIds);

        return DB::transaction(function () use ($actor, $task, $body, $clientRequestId, $fingerprint, $mentionIds) {
            $comment = TaskComment::query()->create([
                'task_id' => $task->getKey(),
                'user_id' => (string) $actor->getKey(),
                'employee_id' => $this->visibility->actorEmployeeId($actor),
                'body' => $body,
                'version' => 1,
                'client_request_id' => $clientRequestId ?: null,
                'client_request_hash' => ($clientRequestId ?? '') === '' ? null : $fingerprint,
            ]);

            foreach ($mentionIds as $userId) {
                TaskCommentMention::query()->create([
                    'comment_id' => $comment->getKey(),
                    'mentioned_user_id' => $userId,
                    'created_at' => now(),
                ]);
            }

            $this->activity->record(TaskActivityType::CommentCreated, $actor, $task->getKey(), $task->project_id, [
                'comment_id' => $comment->getKey(), 'mentions' => count($mentionIds),
            ]);
            $this->audit->log('tasks.comment_created', [
                'actor' => $actor, 'subject' => $task, 'metadata' => ['comment_id' => $comment->getKey()],
            ]);

            return $comment->fresh();
        });
    }

    public function edit(User $actor, Task $task, TaskComment $comment, string $body, ?int $expectedVersion = null): TaskComment
    {
        return DB::transaction(function () use ($actor, $task, $comment, $body, $expectedVersion) {
            $comment = TaskComment::query()->lockForUpdate()->findOrFail($comment->getKey());
            $this->assertActionable($comment, $expectedVersion);
            if ((string) $comment->user_id !== (string) $actor->getKey()) {
                $this->fail(__('tasks.comment_edit_forbidden'));
            }
            $comment->forceFill([
                'body' => $body,
                'edited_at' => CarbonImmutable::now()->utc(),
                'version' => (int) $comment->version + 1,
            ])->save();

            $this->activity->record(TaskActivityType::CommentEdited, $actor, $task->getKey(), $task->project_id, [
                'comment_id' => $comment->getKey(),
            ]);

            return $comment->fresh();
        });
    }

    public function delete(User $actor, Task $task, TaskComment $comment, ?int $expectedVersion = null): void
    {
        DB::transaction(function () use ($actor, $task, $comment, $expectedVersion) {
            $comment = TaskComment::query()->lockForUpdate()->findOrFail($comment->getKey());
            $this->assertActionable($comment, $expectedVersion);
            $isAuthor = (string) $comment->user_id === (string) $actor->getKey();
            if (! $isAuthor && ! $this->authorizer->canManage($actor, $task)) {
                $this->fail(__('tasks.comment_edit_forbidden'));
            }
            $comment->forceFill([
                'deleted_at' => CarbonImmutable::now()->utc(),
                'version' => (int) $comment->version + 1,
            ])->save();

            $this->activity->record(TaskActivityType::CommentDeleted, $actor, $task->getKey(), $task->project_id, [
                'comment_id' => $comment->getKey(),
            ]);
            $this->audit->log('tasks.comment_deleted', [
                'actor' => $actor, 'subject' => $task, 'metadata' => ['comment_id' => $comment->getKey()],
            ]);
        });
    }

    /**
     * @param  array<int, string>  $mentionUserIds
     * @return array<int, string> validated, de-duplicated user ids
     */
    private function validateMentions(Task $task, array $mentionUserIds): array
    {
        $ids = collect($mentionUserIds)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }
        foreach ($ids as $userId) {
            // Must be a member of THIS tenant.
            $isMember = TenantMembership::query()->where('user_id', $userId)->exists();
            $user = User::query()->find($userId);
            if (! $isMember || $user === null || ! $this->visibility->canViewTask($user, $task)) {
                $this->fail(__('tasks.mention_invalid'));
            }
        }

        return $ids->all();
    }

    private function assertActionable(TaskComment $comment, ?int $expectedVersion): void
    {
        if ($comment->isDeleted()) {
            $this->fail(__('tasks.comment_deleted'));
        }
        if ($expectedVersion !== null && (int) $comment->version !== $expectedVersion) {
            throw new ConflictHttpException(__('tasks.stale'));
        }
    }

    private function fingerprint(array $fields): string
    {
        ksort($fields);

        return hash('sha256', json_encode($fields, JSON_UNESCAPED_UNICODE));
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['comment' => [$message]]);
    }
}
