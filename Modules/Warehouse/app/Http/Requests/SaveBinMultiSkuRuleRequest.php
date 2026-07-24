<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveBinMultiSkuRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $presence = $this->isMethod('post') ? ['required'] : ['sometimes', 'required'];

        return [
            'pattern' => [...$presence, 'string', 'max:100'],
            'note' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'pattern.required' => 'Pola kode rak wajib diisi.',
            'pattern.max' => 'Pola kode rak maksimal 100 karakter.',
        ];
    }
}
