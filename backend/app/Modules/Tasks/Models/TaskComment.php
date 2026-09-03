<?php

namespace App\Modules\Tasks\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Task comment. Author identity is a User; employee_id is a nullable snapshot.
 * Soft-delete only (deleted_at); optimistic version guards edit/delete.
 */
class TaskComment extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'task_id', 'user_id', 'employee_id', 'body', 'version',
        'client_request_id', 'client_request_hash', 'edited_at', 'deleted_at',
    ];

    protected $hidden = ['client_request_hash'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'edited_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(TaskCommentMention::class, 'comment_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TaskAttachment::class, 'comment_id');
    }
}
