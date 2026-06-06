<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'po_number'            => ['nullable', 'string', 'max:50', 'unique:purchase_orders,po_number'],
            'supplier_id'          => ['required', 'string', 'exists:suppliers,id'],
            'location_id'          => ['required', 'string', 'exists:locations,id'],
            'order_date'           => ['required', 'date'],
            'expected_date'        => ['nullable', 'date', 'after_or_equal:order_date'],
            'payment_term'         => ['nullable', 'string', 'max:50'],
            'notes'                => ['nullable', 'string'],
            'created_by'           => ['required', 'string', 'max:100'],
            'items'                => ['required', 'array', 'min:1'],
            'items.*.item_id'      => ['required', 'string', 'size:32', 'exists:products,id'],
            'items.*.qty'          => ['required', 'integer', 'min:1'],
            'items.*.unit_price'   => ['required', 'numeric', 'min:0'],
        ];
    }
}
