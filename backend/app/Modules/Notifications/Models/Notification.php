<?php

namespace App\Modules\Notifications\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An in-app notification addressed to exactly one recipient User (Sprint 8B).
 *
 * Persistence is created ONLY through NotificationService (writer context); the
 * inbox endpoints never create rows and may mutate only read_at. subject_type/
 * subject_id are metadata (no relation is resolved during listing). There is no
 * updated_at column — read_at is the sole mutable field.
 */
class Notification extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const UPDATED_AT = null;

    /** recipient_user_id / tenant_id are set internally, never from request input. */
    protected $fillable = [
        'tenant_id', 'recipient_user_id', 'type', 'subject_type', 'subject_id',
        'data', 'dedupe_key', 'read_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
