<?php

namespace App\Modules\Tasks\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A User's notification preference on a task. Never grants visibility. */
class TaskWatcher extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'task_id', 'user_id', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
