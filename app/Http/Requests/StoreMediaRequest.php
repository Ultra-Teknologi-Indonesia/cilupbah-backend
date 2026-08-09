<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:51200', 'mimes:jpg,jpeg,png,webp,gif,pdf'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'File wajib diunggah.',
            'file.file' => 'Unggahan harus berupa file yang valid.',
            'file.max' => 'Ukuran file maksimal 50MB.',
            'file.mimes' => 'Tipe file tidak didukung. Gunakan gambar (jpg, png, webp, gif) atau PDF.',
        ];
    }
}
