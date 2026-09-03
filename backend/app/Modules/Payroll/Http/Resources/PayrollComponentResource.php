<?php

namespace App\Modules\Payroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollComponentResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type?->value,
            'calculation_mode' => $this->calculation_mode?->value,
            'active' => (bool) $this->active,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
