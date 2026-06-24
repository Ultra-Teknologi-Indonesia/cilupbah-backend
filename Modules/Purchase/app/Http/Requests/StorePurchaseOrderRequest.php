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
        $poUnique = $this->route('id')
            ? 'unique:purchase_orders,po_number,' . $this->route('id')
            : 'unique:purchase_orders,po_number';

        return [
            'po_number'              => ['nullable', 'string', 'max:50', $poUnique],
            'contact_id'             => ['required', 'string', 'exists:contacts,id'],
            'location_id'            => ['required', 'string', 'exists:locations,id'],
            'order_date'             => ['required', 'date'],
            'expected_date'          => ['nullable', 'date', 'after_or_equal:order_date'],
            'ref_no'                 => ['nullable', 'string', 'max:100'],
            'payment_term'           => ['nullable', 'integer', 'min:0'],
            'is_tax_included'        => ['nullable', 'boolean'],
            'notes'                  => ['nullable', 'string'],
            'items'                  => ['required', 'array', 'min:1'],
            'items.*.item_id'        => ['required', 'string', 'exists:products,id'],
            'items.*.description'    => ['nullable', 'string'],
            'items.*.unit'           => ['nullable', 'string', 'max:30'],
            'items.*.qty'            => ['required', 'integer', 'min:1'],
            'items.*.unit_price'     => ['required', 'numeric', 'min:0'],
            'items.*.disc'           => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.tax_id'         => ['nullable', 'string', 'exists:taxes,id'],
        ];
    }
}
