<?php

namespace App\Modules\Organization\Models;

use App\Modules\Employees\Models\Employee;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\JobTitleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobTitle extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'title', 'code', 'description', 'level', 'status',
    ];

    protected static function newFactory(): JobTitleFactory
    {
        return JobTitleFactory::new();
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
