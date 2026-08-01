<?php

namespace Modules\Outbound\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateHandoverQtyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_id' => 'required|string|exists:sales_orders,id',
            'qty_given' => 'required|integer|min:0',
        ];
    }
}
