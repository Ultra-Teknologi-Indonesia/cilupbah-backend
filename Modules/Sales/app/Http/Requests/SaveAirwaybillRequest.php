<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveAirwaybillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_id'          => 'required|bail|uuid|exists:sales_orders,id',
            'tracking_number'   => 'required|string|max:255',
            'shipping_provider' => 'nullable|string|max:255',
        ];
    }
}
