<?php

namespace App\Modules\Billing\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Per-tenant monotonic counter (see InvoiceNumberGenerator). */
class BillingCounter extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['tenant_id', 'key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'integer'];
    }
}
