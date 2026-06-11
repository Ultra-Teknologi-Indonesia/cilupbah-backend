<?php

namespace Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Set/lepas avatar user. media_uuid mengacu ke media terpusat (Spatie media.uuid).
 * bail+uuid mencegah error cast uuid Postgres (500) saat input non-UUID.
 */
class UpdateAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Wajib hadir di body (boleh null untuk melepas avatar).
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
