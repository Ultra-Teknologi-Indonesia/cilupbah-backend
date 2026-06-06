<?php

namespace Modules\Warranty\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWarrantyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_variant_id' => ['required', 'string', 'exists:product_variants,id'],
            'order_id' => ['nullable', 'string', 'exists:orders,id'],
            'serial_no' => ['nullable', 'string', 'max:255'],
            'duration_months' => ['required', 'integer', 'min:1'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
