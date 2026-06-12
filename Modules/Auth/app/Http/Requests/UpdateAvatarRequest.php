<?php

namespace Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'media_uuid' => ['present', 'nullable', 'bail', 'uuid', 'exists:media,uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'media_uuid.present' => 'Field media_uuid wajib dikirim (boleh null untuk melepas avatar).',
            'media_uuid.exists' => 'Media tidak ditemukan.',
        ];
    }
}
