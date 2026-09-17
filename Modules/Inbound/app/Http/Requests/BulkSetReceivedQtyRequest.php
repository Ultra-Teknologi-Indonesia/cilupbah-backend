<?php

namespace Modules\Inbound\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkSetReceivedQtyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.item_id' => ['required', 'string', 'distinct'],
            'items.*.qty' => ['required', 'integer', 'min:0'],
            'items.*.reason_note' => ['nullable', 'string'],
            '_expected_updated_at' => ['nullable', 'string'],
        ];
    }
}
