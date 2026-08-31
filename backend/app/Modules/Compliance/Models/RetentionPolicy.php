<?php

namespace App\Modules\Compliance\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** GDPR retention policy (concept-level foundation, ADR-013). */
class RetentionPolicy extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'data_class', 'retention_days', 'action', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'retention_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
