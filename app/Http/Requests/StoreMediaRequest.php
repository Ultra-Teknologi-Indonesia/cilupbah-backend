<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi upload/replace media. Menerima SEMUA tipe file, dibatasi ukuran.
 * Batas default 50MB; atur via config('media-library.max_file_size') bila perlu.
 */
class StoreMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:51200'], // 50 MB (KB)
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'File wajib diunggah.',
            'file.file' => 'Unggahan harus berupa file yang valid.',
            'file.max' => 'Ukuran file maksimal 50MB.',
        ];
    }
}
