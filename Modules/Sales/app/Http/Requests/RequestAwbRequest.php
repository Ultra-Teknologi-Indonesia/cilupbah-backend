<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RequestAwbRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_id'     => 'required|bail|uuid|exists:sales_orders,id',
            'courier_code' => 'nullable|string|max:50',
        ];
    }
}
