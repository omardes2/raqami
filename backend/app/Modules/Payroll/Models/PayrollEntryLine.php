<?php

namespace App\Modules\Payroll\Models;

use App\Modules\Payroll\Enums\PayrollLineDirection;
use App\Modules\Payroll\Enums\PayrollLineType;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A generated, explainable earning/deduction line. Regenerated wholesale on
 * (re)calculation, so there is no updated_at. amount_minor is a non-negative
 * magnitude; direction carries the sign.
 */
class PayrollEntryLine extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'payroll_entry_id',
        'line_code', 'line_type', 'direction',
        'source_type', 'source_id', 'label_snapshot',
        'quantity_minutes', 'rate_minor_per_hour', 'rate_bps',
        'amount_minor', 'metadata', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'line_type' => PayrollLineType::class,
            'direction' => PayrollLineDirection::class,
            'quantity_minutes' => 'integer',
            'rate_minor_per_hour' => 'integer',
            'rate_bps' => 'integer',
            'amount_minor' => 'integer',
            'metadata' => 'array',
            'sort_order' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(PayrollEntry::class, 'payroll_entry_id');
    }
}
