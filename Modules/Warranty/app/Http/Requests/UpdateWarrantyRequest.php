<?php

namespace Modules\Warranty\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWarrantyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', 'in:active,expired,void'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
