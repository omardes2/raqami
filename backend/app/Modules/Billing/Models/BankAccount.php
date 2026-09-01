<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform-global bank-transfer destination account. internal_notes is hidden
 * from serialization so it never reaches tenants.
 */
class BankAccount extends Model
{
    use HasUlids;

    protected $fillable = [
        'label', 'bank_name', 'account_holder', 'account_number', 'swift_code',
        'currency', 'country_code', 'instructions', 'internal_notes', 'status',
    ];

    protected $hidden = ['internal_notes'];

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
