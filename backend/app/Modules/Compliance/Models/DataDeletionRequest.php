<?php

namespace App\Modules\Compliance\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * GDPR deletion request (concept-level foundation, ADR-013). Deletion must
 * never casually bypass retention/audit/security — enforced later by workflow.
 */
class DataDeletionRequest extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'requested_by', 'subject_type', 'subject_id',
        'status', 'reason', 'scheduled_for', 'completed_at', 'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
