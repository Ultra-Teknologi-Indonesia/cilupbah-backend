<?php

namespace Modules\Report\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PickListDetailPdfRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'picklist_id' => ['required', 'uuid'],
            'order_ids' => ['nullable', 'array'],
            'order_ids.*' => ['uuid'],
            'download' => ['nullable', 'boolean'],
        ];
    }
}
