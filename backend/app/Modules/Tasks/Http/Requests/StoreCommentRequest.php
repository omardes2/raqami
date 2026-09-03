<?php

namespace App\Modules\Tasks\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:10000'],
            'mentions' => ['sometimes', 'array'],
            'mentions.*' => ['string'],
            'client_request_id' => ['nullable', 'string', 'max:128'],
        ];
    }
}
