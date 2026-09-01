<?php

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Billing\Models\Coupon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Normalize the code to uppercase before validation (case-insensitive unique). */
    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => Coupon::normalizeCode((string) $this->input('code'))]);
        }
    }

    public function rules(): array
    {
        $id = $this->route('coupon')?->id ?? $this->route('coupon');

        return [
            'code' => ['required', 'string', 'max:64', Rule::unique('coupons', 'code')->ignore($id)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['percentage', 'fixed_amount'])],
            'percentage' => ['nullable', 'required_if:type,percentage', 'integer', 'min:1', 'max:100'],
            'amount_minor' => ['nullable', 'required_if:type,fixed_amount', 'integer', 'min:1'],
            'currency' => ['nullable', 'required_if:type,fixed_amount', Rule::in(config('billing.currencies'))],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_at'],
            'max_redemptions' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_tenant_limit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'applicable_plan_id' => ['sometimes', 'nullable', 'string', Rule::exists('plans', 'id')],
            'status' => ['sometimes', Rule::in(['active', 'archived'])],
        ];
    }
}
