<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OpnameScopeColumnRequest extends FormRequest
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
            'row_code' => 'required|string',
            'zone_id' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'location_id.required' => 'Parameter location_id, floor_code, dan row_code wajib diisi.',
            'floor_code.required' => 'Parameter location_id, floor_code, dan row_code wajib diisi.',
            'row_code.required' => 'Parameter location_id, floor_code, dan row_code wajib diisi.',
        ];
    }
}
