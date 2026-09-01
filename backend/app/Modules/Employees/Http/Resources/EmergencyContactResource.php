<?php

namespace App\Modules\Employees\Http\Resources;

use App\Modules\Employees\Models\EmployeeEmergencyContact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmployeeEmergencyContact */
class EmergencyContactResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'relationship' => $this->relationship,
            'phone' => $this->phone,
            'alternate_phone' => $this->alternate_phone,
            'email' => $this->email,
            'is_primary' => $this->is_primary,
        ];
    }
}
