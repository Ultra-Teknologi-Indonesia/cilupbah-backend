<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MarkOrdersCompleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_ids'   => 'required|array|min:1',
            'order_ids.*' => 'required|bail|uuid|exists:sales_orders,id',
        ];
    }
}
