<?php

namespace App\Modules\Tasks\Models;

use App\Modules\Employees\Models\Employee;
use App\Modules\Tasks\Enums\DueType;
use App\Modules\Tasks\Enums\ScopeType;
use App\Modules\Tasks\Enums\TaskPriority;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A task. Standalone (project_id null + stable scope) or inside a project
 * (project_id set + scope inherited). Completion/overdue derive from the status
 * category — never from a mutable flag.
 */
class Task extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'project_id', 'parent_task_id', 'title', 'description',
        'status_id', 'priority', 'scope_type', 'scope_id', 'due_type', 'due_on',
        'due_at', 'due_timezone', 'start_on', 'completed_at', 'archived_at',
        'estimated_minutes', 'board_rank', 'created_by_user_id',
        'created_by_employee_id', 'client_request_id', 'client_request_hash', 'version',
    ];

    protected $hidden = ['client_request_hash'];

    protected function casts(): array
    {
        return [
            'priority' => TaskPriority::class,
            'scope_type' => ScopeType::class,
            'due_type' => DueType::class,
            'due_on' => 'date',
            'due_at' => 'immutable_datetime',
            'start_on' => 'date',
            'completed_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'estimated_minutes' => 'integer',
            'board_rank' => 'integer',
            'version' => 'integer',
        ];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isSubtask(): bool
    {
        return $this->parent_task_id !== null;
    }

    /**
     * Server-authoritative overdue: only a non-terminal, non-archived task with a
     * passed deadline is overdue. Date-only is overdue only AFTER the end of the
     * due local calendar day in the task's timezone; datetime compares instants.
     */
    public function isOverdue(?CarbonImmutable $now = null): bool
    {
        if ($this->isArchived() || $this->completed_at !== null) {
            return false;
        }
        $category = $this->status?->category;
        if ($category !== null && $category->isTerminal()) {
            return false;
        }

        $now ??= CarbonImmutable::now('UTC');

        return match ($this->due_type) {
            DueType::Datetime => $this->due_at !== null && $now->greaterThan($this->due_at),
            DueType::Date => $this->due_on !== null && $now->greaterThan(
                CarbonImmutable::parse($this->due_on->toDateString(), $this->due_timezone ?? 'UTC')->endOfDay()
            ),
            default => false,
        };
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_task_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(self::class, 'parent_task_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(TaskStatus::class, 'status_id');
    }

    public function assignees(): HasMany
    {
        return $this->hasMany(TaskAssignee::class);
    }

    public function creatorEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by_employee_id');
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(TaskChecklistItem::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TaskAttachment::class);
    }

    public function watchers(): HasMany
    {
        return $this->hasMany(TaskWatcher::class);
    }
}
