<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $billUnique = $this->route('id')
            ? 'unique:purchase_bills,bill_number,' . $this->route('id')
            : 'unique:purchase_bills,bill_number';

        return [
            'bill_number'            => ['nullable', 'string', 'max:50', $billUnique],
            'purchase_order_id'      => ['nullable', 'exists:purchase_orders,id'],
            'contact_id'             => ['required', 'exists:contacts,id'],
            'location_id'            => ['required', 'exists:locations,id'],
            'bill_date'              => ['required', 'date'],
            'due_date'               => ['nullable', 'date', 'after_or_equal:bill_date'],
            'ref_no'                 => ['nullable', 'string', 'max:100'],
            'payment_term'           => ['nullable', 'integer', 'min:0'],
            'is_tax_included'        => ['nullable', 'boolean'],
            'tag'                    => ['nullable', 'string', 'max:100'],
            'notes'                  => ['nullable', 'string'],
            'payment_amount'         => ['nullable', 'numeric', 'min:0'],
            'payment_account_id'     => ['nullable', 'string'],
            'items'                  => ['required', 'array', 'min:1'],
            'items.*.item_id'        => ['required', 'exists:products,id'],
            'items.*.purchase_order_item_id' => ['nullable', 'exists:purchase_order_items,id'],
            'items.*.description'    => ['nullable', 'string'],
            'items.*.unit'           => ['nullable', 'string', 'max:30'],
            'items.*.qty'            => ['required', 'integer', 'min:1'],
            'items.*.unit_price'     => ['required', 'numeric', 'min:0'],
            'items.*.disc'           => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.tax_id'         => ['nullable', 'string', 'exists:taxes,id'],
        ];
    }
}
