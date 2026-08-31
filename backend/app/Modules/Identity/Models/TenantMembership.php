<?php

namespace App\Modules\Identity\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Links a global user to a tenant. Tenant-owned (RLS + global scope). */
class TenantMembership extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'user_id', 'status', 'invited_email',
        'invited_by', 'invitation_token', 'invitation_accepted_at',
    ];

    protected function casts(): array
    {
        return ['invitation_accepted_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
