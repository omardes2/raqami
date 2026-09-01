<?php

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Plan */
class PlanResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'status' => $this->status->value,
            'visibility' => $this->visibility->value,
            'monthly_price_minor' => $this->monthly_price_minor,
            'annual_price_minor' => $this->annual_price_minor,
            'currency' => $this->currency,
            'trial_days' => $this->trial_days,
            'employee_limit' => $this->employee_limit,
            'sort_order' => $this->sort_order,
            'is_featured' => $this->is_featured,
            'is_default_trial' => $this->is_default_trial,
            'features' => PlanFeatureResource::collection($this->whenLoaded('features')),
        ];
    }
}
