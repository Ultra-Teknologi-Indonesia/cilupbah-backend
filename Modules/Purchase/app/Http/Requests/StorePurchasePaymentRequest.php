<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchasePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purchase_bill_id' => 'required|exists:purchase_bills,id',
            'amount'           => 'required|numeric|min:0.01',
            'payment_date'     => 'required|date',
            'payment_method'   => 'required|string|max:100',
            'reference_no'     => 'nullable|string|max:255',
            'notes'            => 'nullable|string',
            'created_by'       => 'required|string|max:100',
        ];
    }
}
