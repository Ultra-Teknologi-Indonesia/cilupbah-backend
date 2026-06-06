<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReceivePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'received_by'                       => ['required', 'string', 'max:100'],
            'items'                             => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id'    => ['required', 'string', 'exists:purchase_order_items,id'],
            'items.*.qty'                       => ['required', 'integer', 'min:1'],
        ];
    }
}
