<?php

namespace App\Modules\Compliance\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** GDPR consent record (concept-level foundation, ADR-013). */
class ConsentRecord extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'subject_type', 'subject_id', 'consent_type',
        'granted', 'granted_at', 'revoked_at', 'source',
    ];

    protected function casts(): array
    {
        return [
            'granted' => 'boolean',
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
