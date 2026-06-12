<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSalesReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_id'           => ['nullable', 'bail', 'uuid', 'exists:sales_orders,id'],
            'location_id'        => ['required', 'bail', 'uuid', 'exists:locations,id'],
            'source'             => ['nullable', 'string', 'in:manual,marketplace'],
            'customer_name'      => ['nullable', 'string', 'max:255'],
            'customer_contact'   => ['nullable', 'string', 'max:255'],
            'reason'             => ['nullable', 'string'],
            'notes'              => ['nullable', 'string'],
            'created_by'         => ['required', 'string', 'max:100'],
            'items'              => ['required', 'array', 'min:1'],
            'items.*.item_id'    => ['required', 'bail', 'uuid', 'exists:product_variants,id'],
            'items.*.qty'        => ['required', 'integer', 'min:1'],
            'items.*.condition'  => ['nullable', 'string', 'in:GOOD,DAMAGE'],
            'items.*.notes'      => ['nullable', 'string'],
        ];
    }
}
