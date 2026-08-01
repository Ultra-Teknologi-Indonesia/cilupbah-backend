<?php

namespace Modules\Outbound\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ScanForPickRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sku' => 'required|string',
            'bin_code' => 'nullable|string',
            'hint_active_bin_code' => 'nullable|string',
        ];
    }
}
