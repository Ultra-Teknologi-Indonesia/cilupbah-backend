<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sku'         => 'nullable|string|max:100',
            'description' => 'nullable|string|max:500',
            'qty_in_base' => 'nullable|integer|min:1',
            'price'       => 'nullable|numeric|min:0',
            'disc_amount' => 'nullable|numeric|min:0',
            'tax_amount'  => 'nullable|numeric|min:0',
        ];
    }
}
