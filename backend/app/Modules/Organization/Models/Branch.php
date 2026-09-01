<?php

namespace App\Modules\Organization\Models;

use App\Modules\Employees\Models\Employee;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'name', 'code', 'description', 'country_code', 'city',
        'address_line', 'timezone', 'phone', 'email', 'is_headquarters', 'status',
    ];

    protected function casts(): array
    {
        return ['is_headquarters' => 'boolean'];
    }

    protected static function newFactory(): BranchFactory
    {
        return BranchFactory::new();
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }
}
