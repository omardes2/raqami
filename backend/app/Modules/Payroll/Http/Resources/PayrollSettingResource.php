<?php

namespace App\Modules\Payroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollSettingResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payroll_timezone' => $this->payroll_timezone,
            'overtime_pay_enabled' => (bool) $this->overtime_pay_enabled,
            'require_four_eyes' => (bool) $this->require_four_eyes,
            'allow_self_payroll' => (bool) $this->allow_self_payroll,
            'version' => (int) $this->version,
        ];
    }
}
