<?php

namespace Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'new_password' => [
                'required',
                'string',
                'min:8',
                'max:64',
                'regex:/[A-Za-z]/',
                'regex:/\d/',
                'confirmed',
                'different:current_password',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'new_password.regex' => 'Kata sandi baru wajib memuat setidaknya satu huruf dan satu angka.',
            'new_password.different' => 'Kata sandi baru tidak boleh sama dengan kata sandi saat ini.',
            'new_password.confirmed' => 'Konfirmasi kata sandi baru tidak cocok.',
        ];
    }
}
