<?php

namespace App\Modules\Tasks\Models;

use App\Modules\Employees\Models\Employee;
use App\Modules\Tasks\Enums\ProjectMembershipRole;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bounded project-local ACL row (manager|member). Owner is never a row. */
class ProjectMembership extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'project_id', 'employee_id', 'role', 'added_by_user_id', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'role' => ProjectMembershipRole::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
