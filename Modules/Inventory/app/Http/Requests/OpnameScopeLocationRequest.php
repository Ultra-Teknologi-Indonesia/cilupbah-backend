<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OpnameScopeLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'location_id' => 'required|string',
            'zone_id' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'location_id.required' => 'Parameter location_id wajib diisi.',
        ];
    }
}
