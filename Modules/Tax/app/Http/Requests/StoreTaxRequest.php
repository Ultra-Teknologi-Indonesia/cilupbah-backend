<?php

namespace Modules\Tax\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTaxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => "$required|string|max:255",
            'rate' => "$required|numeric|min:0|max:100",
            'is_active' => 'nullable|boolean',
        ];
    }
}
