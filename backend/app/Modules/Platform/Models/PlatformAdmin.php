<?php

namespace App\Modules\Platform\Models;

use Database\Factories\PlatformAdminFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Platform Super Admin identity. SEPARATE from tenant users and tenant RBAC.
 * Authenticates via its own guard; never participates in tenant authorization.
 */
class PlatformAdmin extends Authenticatable
{
    use HasFactory;
    use HasUlids;
    use Notifiable;

    protected $fillable = ['name', 'email', 'password', 'status'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function newFactory(): PlatformAdminFactory
    {
        return PlatformAdminFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
