<?php

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Subscription */
class SubscriptionResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plan_id' => $this->plan_id,
            'plan' => new PlanResource($this->whenLoaded('plan')),
            'status' => $this->status->value,
            'is_usable' => $this->isUsable(),
            'billing_interval' => $this->billing_interval->value,
            'currency' => $this->currency,
            'started_at' => $this->started_at,
            'trial_started_at' => $this->trial_started_at,
            'trial_ends_at' => $this->trial_ends_at,
            'trial_days_remaining' => $this->trial_ends_at && $this->onTrial()
                ? max(0, (int) now()->diffInDays($this->trial_ends_at, false))
                : null,
            'current_period_start' => $this->current_period_start,
            'current_period_end' => $this->current_period_end,
            'cancel_at_period_end' => $this->cancel_at_period_end,
            'canceled_at' => $this->canceled_at,
            'ended_at' => $this->ended_at,
            'grace_ends_at' => $this->grace_ends_at,
        ];
    }
}
