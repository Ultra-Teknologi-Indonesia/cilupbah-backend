<?php

namespace Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
                'confirmed',
                'different:current_password',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (! is_string($value)) {
                        return;
                    }

                    if (! preg_match('/[A-Z]/', $value)) {
                        $fail('Kata sandi baru wajib memuat setidaknya satu huruf besar.');
                    }
                    if (! preg_match('/[a-z]/', $value)) {
                        $fail('Kata sandi baru wajib memuat setidaknya satu huruf kecil.');
                    }
                    if (! preg_match('/\d/', $value)) {
                        $fail('Kata sandi baru wajib memuat setidaknya satu angka.');
                    }
                    if (! preg_match('/[^A-Za-z0-9]/', $value)) {
                        $fail('Kata sandi baru wajib memuat setidaknya satu simbol (mis. ! @ # $ %).');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => 'Kata sandi saat ini wajib diisi.',
            'new_password.required'     => 'Kata sandi baru wajib diisi.',
            'new_password.min'          => 'Kata sandi baru minimal 8 karakter.',
            'new_password.max'          => 'Kata sandi baru maksimal 64 karakter.',
            'new_password.confirmed'    => 'Konfirmasi kata sandi baru tidak cocok.',
            'new_password.different'    => 'Kata sandi baru tidak boleh sama dengan kata sandi saat ini.',
        ];
    }
}
