<?php

namespace App\Modules\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubscribeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware enforces permission
    }

    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'string', Rule::exists('plans', 'id')],
            'interval' => ['required', Rule::in(['monthly', 'annual'])],
            'currency' => ['sometimes', Rule::in(config('billing.currencies'))],
            'coupon_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'trial' => ['sometimes', 'boolean'],
        ];
    }
}
