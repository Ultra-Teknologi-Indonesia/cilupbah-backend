<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purchase_order_id'   => 'nullable|exists:purchase_orders,id',
            'supplier_id'         => 'required|exists:suppliers,id',
            'location_id'         => 'required|exists:locations,id',
            'return_date'         => 'required|date',
            'reason'              => 'nullable|string',
            'notes'               => 'nullable|string',
            'created_by'          => 'required|string|max:100',
            'items'               => 'required|array|min:1',
            'items.*.item_id'     => 'required|exists:product_variants,id',
            'items.*.qty'         => 'required|integer|min:1',
            'items.*.unit_price'  => 'required|numeric|min:0',
            'items.*.condition'   => 'nullable|string|max:30',
            'items.*.notes'       => 'nullable|string',
        ];
    }
}
