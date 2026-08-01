<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetOrderAsPaidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_id'       => 'required|bail|uuid|exists:sales_orders,id',
            'payment_method' => 'nullable|string|max:100',
            'paid_time'      => 'nullable|date',
        ];
    }
}
