<?php

namespace App\Modules\Ai\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only operational record of one AI call (Sprint 9). Written only through
 * AiUsageLedger; carries no prompt/response content or any employee/payroll data
 * — safe metadata only (provider/model/feature/units/estimated cost/status).
 */
class AiUsageEvent extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'user_id', 'provider', 'model', 'feature',
        'input_units', 'output_units', 'estimated_cost_micro', 'status', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'input_units' => 'integer',
            'output_units' => 'integer',
            'estimated_cost_micro' => 'integer',
            'meta' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
