<?php

namespace Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'nik' => ['nullable', 'string', 'max:32'],
            'phone' => ['nullable', 'string', 'regex:/^\+[1-9]\d{6,14}$/', 'max:16'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Nomor telepon harus dalam format E.164 (+kode negara, contoh +6281234567890).',
        ];
    }
}
