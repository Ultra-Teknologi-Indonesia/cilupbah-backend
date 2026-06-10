<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSalesInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_id'            => ['nullable', 'string', 'exists:sales_orders,id'],
            'customer_name'       => ['nullable', 'string', 'max:255'],
            'location_id'         => ['required', 'string', 'exists:locations,id'],
            'status'              => ['nullable', 'string', 'in:DRAFT,OPEN'],
            'invoice_date'        => ['required', 'date'],
            'due_date'            => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'notes'               => ['nullable', 'string'],
            'created_by'          => ['required', 'string', 'max:100'],
            'items'               => ['required', 'array', 'min:1'],
            'items.*.item_id'     => ['required', 'string', 'exists:product_variants,id'],
            'items.*.qty'         => ['required', 'integer', 'min:1'],
            'items.*.unit_price'  => ['required', 'numeric', 'min:0'],
            'items.*.disc_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_amount'  => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
