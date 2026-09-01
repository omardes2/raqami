<?php

namespace App\Modules\Billing\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Tenant billing/tax identity used on invoices. One per tenant. */
class BillingProfile extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'legal_name', 'billing_email', 'billing_phone',
        'country_code', 'city', 'address_line_1', 'address_line_2',
        'postal_code', 'tax_id', 'preferred_currency', 'invoice_notes',
    ];
}
