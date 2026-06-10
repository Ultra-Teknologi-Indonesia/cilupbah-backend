<?php

namespace Modules\Outbound\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PackItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'qty_packed' => 'required|integer|min:0',
            'barcode_verified' => 'nullable|boolean',
        ];
    }
}
