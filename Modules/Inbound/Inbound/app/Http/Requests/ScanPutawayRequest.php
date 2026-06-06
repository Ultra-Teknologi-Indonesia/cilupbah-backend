<?php

namespace Modules\Inbound\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ScanPutawayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'inbound_item_id' => ['required', 'uuid'],
            'bin_id'          => ['required', 'uuid'],
            'qty'             => ['required', 'integer', 'min:1'],
        ];
    }
}
