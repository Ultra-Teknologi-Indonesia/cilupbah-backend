<?php

namespace Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => [
                'required',
                'string',
                'min:8',
                'max:30',
                'regex:/[a-z]/',
                'regex:/[A-Z]/',
                'regex:/[0-9]/',
                'regex:/[@$!%*#?&]/',
                'confirmed'
            ],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', 'exists:roles,name', 'not_in:owner'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'distinct', 'exists:permissions,name'],
            'nik' => ['nullable', 'string', 'max:255'],
            'warehouse_id' => ['nullable', 'bail', 'uuid', 'exists:locations,id'],
            'avatar_media_id' => ['nullable', 'bail', 'uuid', 'exists:media,uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.regex' => 'Password harus mengandung setidaknya satu huruf besar, satu huruf kecil, satu angka, dan satu karakter khusus (@$!%*#?&).',
            'roles.*.not_in' => 'Role owner tidak dapat diberikan kepada pengguna baru.'
        ];
    }
}
