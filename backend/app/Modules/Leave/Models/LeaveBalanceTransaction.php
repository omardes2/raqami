<?php

namespace App\Modules\Leave\Models;

use App\Modules\Leave\Enums\LedgerTransactionType;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The IMMUTABLE leave ledger — source of balance truth. Append-only: created_at
 * only (no updated_at); UPDATE/DELETE blocked by RLS + a DB trigger. Corrections
 * are compensating reversal/adjustment rows. Tenant-owned (tenant_id + RLS).
 */
class LeaveBalanceTransaction extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'employee_id', 'leave_type_id', 'leave_policy_id',
        'entitlement_period_id', 'leave_request_id', 'transaction_type', 'minutes',
        'effective_date', 'reference_type', 'reference_id', 'reason',
        'idempotency_key', 'metadata', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'transaction_type' => LedgerTransactionType::class,
            'minutes' => 'integer',
            'effective_date' => 'date',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
    }
}
