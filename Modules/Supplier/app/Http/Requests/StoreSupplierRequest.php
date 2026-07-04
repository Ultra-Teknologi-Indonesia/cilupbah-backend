<?php

namespace Modules\Supplier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code'           => ['nullable', 'string', 'max:50', 'unique:suppliers,code'],
            'name'           => ['required', 'string', 'max:255'],
            'company_name'   => ['nullable', 'string', 'max:255'],
            'email'          => ['nullable', 'email', 'max:255'],
            'phone'          => ['nullable', 'string', 'max:30', 'regex:/^\+[1-9][0-9]{6,14}$/'],
            'address'        => ['nullable', 'string'],
            'city'           => ['nullable', 'string', 'max:255'],
            'tax_id'         => ['nullable', 'string', 'max:50'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'payment_term'   => ['nullable', 'string', 'max:50'],
            'notes'          => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Format No. Telepon tidak valid. Gunakan format internasional, contoh: +628123456789.',
        ];
    }
}
