<?php

namespace Modules\Inbound\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReceiveInboundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [

            'received_by' => ['sometimes', 'nullable', 'string', 'max:100'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.inbound_item_id' => ['required', 'string', 'uuid', 'exists:inbound_items,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.condition' => ['nullable', 'in:GOOD,DAMAGE'],
            'items.*.batch_no' => ['nullable', 'string', 'max:100'],
            'items.*.serial_no' => ['nullable', 'string', 'max:100'],
        ];
    }
}
