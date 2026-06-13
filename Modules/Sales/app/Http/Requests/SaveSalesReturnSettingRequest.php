<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveSalesReturnSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'auto_accept' => ['nullable', 'boolean'],
            'default_restock_location_id' => ['nullable', 'bail', 'uuid', 'exists:locations,id'],
            'allowed_conditions' => ['nullable', 'array', 'min:1'],
            'allowed_conditions.*' => ['string', 'max:50'],
            'allowed_refund_methods' => ['nullable', 'array', 'min:1'],
            'allowed_refund_methods.*' => ['string', 'max:100'],
            'return_validity_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ];
    }
}
