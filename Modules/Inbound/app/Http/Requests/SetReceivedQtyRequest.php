<?php

namespace Modules\Inbound\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetReceivedQtyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'qty'                   => ['required', 'integer', 'min:0'],
            'reason_note'           => ['nullable', 'string'],
            '_expected_updated_at'  => ['nullable', 'string'],
        ];
    }
}
