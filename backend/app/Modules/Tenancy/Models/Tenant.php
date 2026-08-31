<?php

namespace App\Modules\Tenancy\Models;

use App\Modules\Authorization\Models\Role;
use App\Modules\Identity\Models\TenantMembership;
use App\Modules\Identity\Models\User;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The tenant/company registry row. NOT tenant-owned itself (it defines tenants),
 * so it carries no BelongsToTenant trait and no RLS.
 */
class Tenant extends Model
{
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }

    protected $fillable = [
        'name', 'legal_name', 'slug', 'country_code', 'timezone',
        'default_locale', 'default_currency', 'status', 'owner_user_id',
    ];

    protected function casts(): array
    {
        return [
            'deleted_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
