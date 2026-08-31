<?php

namespace App\Modules\Identity\Models;

use App\Modules\Authorization\Models\RoleAssignment;
use App\Modules\Tenancy\Models\Tenant;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A GLOBAL identity. A user is not permanently bound to one tenant — tenant
 * membership is modelled separately, so one person may belong to many
 * companies. This is deliberately separate from the future Employee/HR record.
 */
class User extends Authenticatable implements MustVerifyEmailContract
{
    use HasApiTokens;       // enables future mobile/API token auth (ADR-004)
    use HasFactory;
    use HasUlids;
    use Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'locale', 'timezone', 'status',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_memberships')
            ->withPivot(['status'])
            ->withTimestamps();
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** The user's first active tenant membership (Sprint 0 "active tenant"). */
    public function activeMembership(): ?TenantMembership
    {
        return $this->memberships()->where('status', 'active')->orderBy('created_at')->first();
    }
}
