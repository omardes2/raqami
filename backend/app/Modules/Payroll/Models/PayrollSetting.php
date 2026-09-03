<?php

namespace App\Modules\Payroll\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Per-tenant payroll configuration (one row per tenant). Tenant-owned (RLS). */
class PayrollSetting extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'payroll_timezone', 'overtime_pay_enabled',
        'require_four_eyes', 'allow_self_payroll', 'version',
    ];

    protected function casts(): array
    {
        return [
            'overtime_pay_enabled' => 'boolean',
            'require_four_eyes' => 'boolean',
            'allow_self_payroll' => 'boolean',
            'version' => 'integer',
        ];
    }
}
