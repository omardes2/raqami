<?php

namespace App\Modules\Tasks\Support;

use App\Modules\Tasks\Enums\TaskActivityType;
use App\Modules\Tasks\Models\TaskActivityEvent;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes the append-only, user-facing activity timeline (D4). metadata is
 * restricted to IDs / enum transitions / safe labels / non-sensitive scalars —
 * NEVER comment bodies, file bytes, storage keys, or sensitive text. Security
 * audit stays with AuditLogger (separate store).
 */
class TaskActivityRecorder
{
    /** Metadata keys that must never be persisted into the activity feed. */
    private const FORBIDDEN = ['body', 'storage_key', 'contents', 'file', 'secret', 'token', 'password'];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array<string, scalar|null>  $metadata
     */
    public function record(
        TaskActivityType $type,
        ?Model $actor = null,
        ?string $taskId = null,
        ?string $projectId = null,
        array $metadata = [],
    ): TaskActivityEvent {
        return TaskActivityEvent::query()->create([
            'task_id' => $taskId,
            'project_id' => $projectId,
            'actor_user_id' => $actor?->getKey(),
            'event_type' => $type->value,
            'metadata' => $this->sanitize($metadata),
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, scalar|null>
     */
    private function sanitize(array $metadata): array
    {
        $out = [];
        foreach ($metadata as $key => $value) {
            if (in_array(strtolower((string) $key), self::FORBIDDEN, true)) {
                continue;
            }
            // Only keep safe scalars — never nested payloads that could carry text.
            if ($value === null || is_scalar($value)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
