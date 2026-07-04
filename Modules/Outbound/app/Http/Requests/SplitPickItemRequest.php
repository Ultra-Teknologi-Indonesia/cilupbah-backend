<?php

namespace Modules\Outbound\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SplitPickItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'allocations' => 'required|array|min:2',
            'allocations.*.bin_code' => 'required|string',
            'allocations.*.qty' => 'required|integer|min:1',
        ];
    }
}
