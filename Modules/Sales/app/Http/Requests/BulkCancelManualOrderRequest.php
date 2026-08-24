<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkCancelManualOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_ids'   => 'required|array|min:1',
            'order_ids.*' => 'required|uuid|exists:sales_orders,id',
            'reason'      => 'nullable|string|max:255',
        ];
    }
}
