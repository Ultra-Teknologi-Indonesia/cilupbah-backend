<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkMarkContactedRequest extends FormRequest
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
            'channel'     => 'nullable|string|in:marketplace_chat,whatsapp,phone,other',
            'note'        => 'nullable|string|max:500',
        ];
    }
}
