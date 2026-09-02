<?php

namespace App\Modules\Tasks\Models;

use App\Modules\Employees\Models\Employee;
use App\Modules\Tasks\Enums\ProjectStatus;
use App\Modules\Tasks\Enums\ProjectVisibility;
use App\Modules\Tasks\Enums\ScopeType;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned work container. Archive is orthogonal (`archived_at`), not a
 * status. Organizational placement is a single stable (scope_type, scope_id).
 */
class Project extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'name', 'code', 'description', 'status', 'visibility',
        'scope_type', 'scope_id', 'owner_employee_id', 'start_on', 'due_on',
        'completed_at', 'archived_at', 'created_by_user_id', 'version',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'visibility' => ProjectVisibility::class,
            'scope_type' => ScopeType::class,
            'start_on' => 'date',
            'due_on' => 'date',
            'completed_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'owner_employee_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(ProjectMembership::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
