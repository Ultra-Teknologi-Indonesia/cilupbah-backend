<?php

namespace Modules\Warranty\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWarrantyClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warranty_id' => ['required', 'string', 'exists:warranties,id'],
            'reason' => ['required', 'string'],
        ];
    }
}
