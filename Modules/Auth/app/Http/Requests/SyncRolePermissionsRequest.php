<?php

namespace Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncRolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array'],
            'permissions.*' => ['required', 'string', 'exists:permissions,name'],
        ];
    }

    public function messages(): array
    {
        return [
            'permissions.present' => 'Field permissions wajib dikirim (boleh array kosong untuk mencabut semua).',
            'permissions.*.exists' => 'Permission tidak ditemukan.',
        ];
    }
}
