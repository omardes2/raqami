<?php

namespace App\Modules\Payroll\Models;

use App\Modules\Payroll\Enums\PayrollComponentMode;
use App\Modules\Payroll\Enums\PayrollComponentType;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Tenant catalog of recurring compensation components. Tenant-owned (RLS). */
class PayrollComponent extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'code', 'name', 'type', 'calculation_mode', 'active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'type' => PayrollComponentType::class,
            'calculation_mode' => PayrollComponentMode::class,
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
