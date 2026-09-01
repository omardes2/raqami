<?php

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Models\PlanFeature;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PlanFeature */
class PlanFeatureResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'feature_key' => $this->feature_key,
            'enabled' => $this->enabled,
            'limit_value' => $this->limit_value,
            'metadata' => $this->metadata,
        ];
    }
}
