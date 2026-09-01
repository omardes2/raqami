<?php

namespace App\Modules\Attendance\Http\Requests;

use App\Modules\Organization\Models\Branch;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttendanceLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Explicit tenant-integrity check: a location may only reference a branch in
     * the active tenant. Branch is tenant-scoped (global scope + RLS), so an id
     * from another tenant is invisible and rejected with a clean 422 — never
     * stored as a dangling cross-tenant relation.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $branchId = $this->input('branch_id');
            if ($branchId !== null && ! Branch::query()->whereKey($branchId)->exists()) {
                $v->errors()->add('branch_id', 'The selected branch does not exist in this tenant.');
            }
        });
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'branch_id' => ['nullable', 'string', 'size:26'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:150'],
            'latitude' => [$creating ? 'required' : 'sometimes', 'numeric', 'between:-90,90'],
            'longitude' => [$creating ? 'required' : 'sometimes', 'numeric', 'between:-180,180'],
            'radius_meters' => [$creating ? 'required' : 'sometimes', 'integer', 'min:10', 'max:100000'],
            'require_accuracy_meters' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'status' => ['sometimes', Rule::in(['active', 'archived'])],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
