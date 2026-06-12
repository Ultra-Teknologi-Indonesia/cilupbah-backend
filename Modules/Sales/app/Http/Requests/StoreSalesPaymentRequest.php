<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSalesPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sales_invoice_id' => ['required', 'bail', 'uuid', 'exists:sales_invoices,id'],
            'amount'           => ['required', 'numeric', 'min:0.01'],
            'payment_date'     => ['required', 'date'],
            'payment_method'   => ['required', 'string', 'max:100'],
            'reference_no'     => ['nullable', 'string'],
            'notes'            => ['nullable', 'string'],
            'created_by'       => ['required', 'string', 'max:100'],
        ];
    }
}
