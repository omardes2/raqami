<?php

namespace App\Modules\Organization\Models;

use App\Modules\Employees\Models\Employee;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'branch_id', 'department_id', 'name', 'code',
        'description', 'team_lead_employee_id', 'status',
    ];

    protected static function newFactory(): TeamFactory
    {
        return TeamFactory::new();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'team_lead_employee_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TeamMembership::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'team_memberships')
            ->withPivot(['role_in_team'])
            ->withTimestamps();
    }
}
