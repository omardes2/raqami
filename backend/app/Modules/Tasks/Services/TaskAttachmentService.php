<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Tasks\Enums\TaskActivityType;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskAttachment;
use App\Modules\Tasks\Models\TaskComment;
use App\Modules\Tasks\Support\TaskActivityRecorder;
use App\Modules\Tasks\Support\TaskAuthorizer;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Private task/comment attachments (§26). Reuses the Sprint 5 pattern: private
 * disk, tenant-prefixed keys, metadata-only rows, hidden storage_key, authorized
 * streamed/signed downloads, no public URL. A comment-scoped attachment must
 * belong to the SAME task (validated explicitly).
 */
class TaskAttachmentService
{
    public function __construct(
        private readonly TaskAuthorizer $authorizer,
        private readonly TaskActivityRecorder $activity,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    public function store(User $actor, Task $task, UploadedFile $file, ?string $commentId = null): TaskAttachment
    {
        if ($commentId !== null) {
            $comment = TaskComment::query()->find($commentId);
            if ($comment === null || (string) $comment->task_id !== (string) $task->getKey()) {
                $this->fail(__('tasks.attachment_comment_mismatch'));
            }
        }

        $tenantId = $this->context->tenantId();
        $key = sprintf('tasks/%s/%s/%s', $tenantId, $task->getKey(), Str::ulid().'-'.$file->getClientOriginalName());
        Storage::disk($this->disk())->putFileAs('', $file, $key, ['visibility' => 'private']);

        $attachment = TaskAttachment::query()->create([
            'task_id' => $task->getKey(),
            'comment_id' => $commentId,
            'uploaded_by_user_id' => (string) $actor->getKey(),
            'storage_key' => $key,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize() ?: 0,
        ]);

        $this->activity->record(TaskActivityType::AttachmentAdded, $actor, $task->getKey(), $task->project_id, [
            'attachment_id' => $attachment->getKey(),
        ]);
        $this->audit->log('tasks.attachment_uploaded', [
            'actor' => $actor, 'subject' => $task,
            'metadata' => ['attachment_id' => $attachment->getKey(), 'filename' => $attachment->original_filename],
        ]);

        return $attachment;
    }

    public function download(TaskAttachment $attachment): StreamedResponse
    {
        return Storage::disk($this->disk())->download($attachment->storage_key, $attachment->original_filename);
    }

    public function delete(User $actor, Task $task, TaskAttachment $attachment): void
    {
        if (! $this->authorizer->canManage($actor, $task)
            && (string) $attachment->uploaded_by_user_id !== (string) $actor->getKey()) {
            $this->fail(__('tasks.task_forbidden'));
        }

        Storage::disk($this->disk())->delete($attachment->storage_key);

        $this->activity->record(TaskActivityType::AttachmentDeleted, $actor, $task->getKey(), $task->project_id, [
            'attachment_id' => $attachment->getKey(),
        ]);
        $this->audit->log('tasks.attachment_deleted', [
            'actor' => $actor, 'subject' => $task, 'metadata' => ['attachment_id' => $attachment->getKey()],
        ]);

        $attachment->delete();
    }

    private function disk(): string
    {
        return config('filesystems.default', 'local');
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['attachment' => [$message]]);
    }
}
