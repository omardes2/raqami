<?php

namespace App\Modules\Tasks\Models;

use App\Modules\Tasks\Enums\TaskStatusCategory;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-wide task status catalog. `category` is semantic truth; `bootstrap_key`
 * is the immutable system identity for idempotent bootstrap.
 */
class TaskStatus extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'name', 'code', 'bootstrap_key', 'category', 'color',
        'sort_order', 'is_default', 'active',
    ];

    protected function casts(): array
    {
        return [
            'category' => TaskStatusCategory::class,
            'sort_order' => 'integer',
            'is_default' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'status_id');
    }
}
