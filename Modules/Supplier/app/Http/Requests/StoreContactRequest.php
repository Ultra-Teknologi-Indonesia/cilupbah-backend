<?php

namespace Modules\Supplier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'           => 'required|string|max:255',
            'company_name'   => 'nullable|string|max:255',
            'email'          => 'nullable|email|max:255',
            'phone'          => 'nullable|string|max:30',
            'mobile'         => 'nullable|string|max:30',
            'address'        => 'nullable|string',
            'city'           => 'nullable|string|max:255',
            'province'       => 'nullable|string|max:255',
            'postal_code'    => 'nullable|string|max:10',
            'tax_id'         => 'nullable|string|max:50',
            'contact_person' => 'nullable|string|max:255',
            'payment_term'   => 'nullable|string|max:50',
            'notes'          => 'nullable|string',
            'status'         => 'nullable|string|in:active,inactive',
            'type'           => 'required|string|in:CUSTOMER,SUPPLIER,BOTH',
            'category_id'    => 'nullable|exists:contact_categories,id',
        ];
    }
}
