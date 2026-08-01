<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSalesOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_name'    => 'sometimes|string|max:255',
            'shipping_address' => 'sometimes|string',
            'seller_note'      => 'sometimes|nullable|string',
            'status'           => 'sometimes|string|in:pending,reserved,picked,packed,shipped,cancelled',
            'cancel_reason'    => 'nullable|string|max:255',
        ];
    }
}
