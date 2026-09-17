<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkSalesReturnActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'return_ids' => ['required', 'array', 'min:1', 'max:500'],
            'return_ids.*' => ['required', 'bail', 'uuid', 'distinct'],
            'processed_by' => ['nullable', 'string', 'max:100'],
            'reason' => ['nullable', 'string'],
        ];
    }
}
