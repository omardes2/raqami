<?php

namespace App\Modules\Audit\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit entry. UPDATE/DELETE are blocked by RLS + a DB trigger.
 * Not using BelongsToTenant because tenant_id is nullable (platform/system
 * actions); read isolation is enforced by RLS and by explicit query scoping.
 */
class AuditLog extends Model
{
    use HasUlids;

    const UPDATED_AT = null; // append-only: only created_at is tracked

    protected $fillable = [
        'tenant_id', 'actor_user_id', 'actor_type', 'actor_label',
        'action', 'subject_type', 'subject_id', 'ip', 'user_agent', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
