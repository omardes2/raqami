<?php

namespace App\Modules\Tasks\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Private task/comment attachment metadata. storage_key is never serialized;
 * downloads go via the authorized streamed/signed route.
 */
class TaskAttachment extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'task_id', 'comment_id', 'uploaded_by_user_id',
        'storage_key', 'original_filename', 'mime_type', 'size_bytes', 'created_at',
    ];

    /** storage_key is never serialized to clients. */
    protected $hidden = ['storage_key'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(TaskComment::class, 'comment_id');
    }
}
