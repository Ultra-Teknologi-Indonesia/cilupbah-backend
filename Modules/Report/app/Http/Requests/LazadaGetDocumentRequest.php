<?php

namespace Modules\Report\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LazadaGetDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_id' => ['nullable', 'string'],
            'shop_id' => ['nullable', 'string'],
            'doc_type' => ['nullable', 'string'],
        ];
    }
}
