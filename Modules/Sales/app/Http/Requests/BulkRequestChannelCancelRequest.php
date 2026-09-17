<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkRequestChannelCancelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_ids' => ['required', 'array', 'min:1', 'max:500'],
            'order_ids.*' => ['required', 'bail', 'uuid', 'distinct'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
