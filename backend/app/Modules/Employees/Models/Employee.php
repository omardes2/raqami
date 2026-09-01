<?php

namespace App\Modules\Employees\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Branch;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\JobTitle;
use App\Modules\Organization\Models\Team;
use App\Modules\Organization\Models\TeamMembership;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The Employee HR record. Deliberately SEPARATE from User (auth identity):
 * an Employee may have no user_id at all, and a User may exist without an
 * Employee record.
 */
class Employee extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    /**
     * Attributes considered sensitive — only exposed to callers holding the
     * employees.view_sensitive permission (see EmployeeResource).
     */
    public const SENSITIVE_ATTRIBUTES = [
        'personal_email', 'mobile_phone', 'date_of_birth', 'address_line',
        'nationality', 'notes',
    ];

    protected $fillable = [
        'tenant_id', 'employee_number',
        'first_name', 'middle_name', 'last_name', 'display_name',
        'branch_id', 'department_id', 'job_title_id', 'direct_manager_employee_id',
        'employment_status', 'employment_type', 'hire_date', 'probation_end_date',
        'termination_date', 'termination_reason',
        'user_id',
        'work_email', 'personal_email', 'work_phone', 'mobile_phone',
        'date_of_birth', 'gender', 'nationality', 'country_code', 'address_line', 'city',
        'notes', 'status',
    ];

    protected function casts(): array
    {
        return [
            'hire_date' => 'date',
            'probation_end_date' => 'date',
            'termination_date' => 'date',
            'date_of_birth' => 'date',
            'deleted_at' => 'datetime',
        ];
    }

    protected static function newFactory(): EmployeeFactory
    {
        return EmployeeFactory::new();
    }

    public function fullName(): string
    {
        return $this->display_name
            ?: trim("{$this->first_name} {$this->last_name}");
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function jobTitle(): BelongsTo
    {
        return $this->belongsTo(JobTitle::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'direct_manager_employee_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(self::class, 'direct_manager_employee_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function teamMemberships(): HasMany
    {
        return $this->hasMany(TeamMembership::class);
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_memberships')
            ->withPivot(['role_in_team'])
            ->withTimestamps();
    }

    public function emergencyContacts(): HasMany
    {
        return $this->hasMany(EmployeeEmergencyContact::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(EmployeeContract::class);
    }

    public function historyEvents(): HasMany
    {
        return $this->hasMany(EmployeeHistoryEvent::class);
    }
}
