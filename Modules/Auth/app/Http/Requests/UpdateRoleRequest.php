<?php

namespace Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $roleId = $this->route('id');

        return [
            'name' => [
                'required', 
                'string', 
                'max:255', 
                Rule::unique('roles', 'name')->ignore($roleId)
            ],
        ];
    }
}
