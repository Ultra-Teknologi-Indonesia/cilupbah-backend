<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OpnameScopeRowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'location_id' => 'required|string',
            'floor_code' => 'required|string',
            'zone_id' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'location_id.required' => 'Parameter location_id dan floor_code wajib diisi.',
            'floor_code.required' => 'Parameter location_id dan floor_code wajib diisi.',
        ];
    }
}
