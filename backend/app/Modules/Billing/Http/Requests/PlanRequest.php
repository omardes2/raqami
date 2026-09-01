<?php

namespace App\Modules\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // platform guard enforces access
    }

    public function rules(): array
    {
        $id = $this->route('plan')?->id ?? $this->route('plan');

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:100', 'alpha_dash', Rule::unique('plans', 'slug')->ignore($id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status' => ['sometimes', Rule::in(['draft', 'active', 'archived'])],
            'visibility' => ['sometimes', Rule::in(['public', 'private', 'enterprise_only'])],
            'monthly_price_minor' => ['required', 'integer', 'min:0'],
            'annual_price_minor' => ['required', 'integer', 'min:0'],
            'currency' => ['required', Rule::in(config('billing.currencies'))],
            'trial_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'employee_limit' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'sort_order' => ['sometimes', 'integer'],
            'is_featured' => ['sometimes', 'boolean'],
        ];
    }
}
