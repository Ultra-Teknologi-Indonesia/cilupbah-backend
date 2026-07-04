<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInternalStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'      => ['required', 'string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
            'logo'      => ['nullable', 'file', 'image', 'max:2048'],
        ];
    }
}
