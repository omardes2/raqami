<?php

namespace App\Modules\Ai\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an AI insight request. Authorization is enforced by route middleware
 * (permission.any:ai.use) and, per feature, by the report-permission checks and
 * scope-enforcing data services inside AiInsightService.
 */
class AiInsightRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'feature' => ['required', 'string', Rule::in([
                'dashboard_summary', 'attendance_insights', 'task_workload', 'report_explanation',
            ])],
            'report' => ['nullable', 'string', Rule::in(['attendance', 'tasks', 'dashboard'])],
        ];
    }
}
