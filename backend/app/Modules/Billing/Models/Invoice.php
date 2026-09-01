<?php

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A SaaS invoice. All money fields are integer minor units and are ALWAYS
 * recomputed server-side. Tenant-owned (tenant_id + RLS).
 */
class Invoice extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'subscription_id', 'invoice_number', 'status', 'currency',
        'subtotal_minor', 'discount_minor', 'tax_minor', 'total_minor',
        'amount_paid_minor', 'amount_due_minor', 'tax_rate', 'tax_label',
        'coupon_id', 'coupon_code', 'issued_at', 'due_at', 'paid_at',
        'billing_period_start', 'billing_period_end', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'amount_paid_minor' => 'integer',
            'amount_due_minor' => 'integer',
            'tax_rate' => 'decimal:3',
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'billing_period_start' => 'datetime',
            'billing_period_end' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
