<?php

namespace Modules\IssueTracker\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'platform' => ['required', 'in:website,mobile_app'],
            'category_id' => ['nullable', 'exists:issue_tracker_categories,id'],
            'priority' => ['nullable', 'in:low,medium,high,critical'],
            'reporter_name' => ['required', 'string', 'max:100'],
            'reporter_contact' => ['nullable', 'string', 'max:255'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,gif,webp,mp4,webm,pdf'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Judul issue wajib diisi.',
            'description.required' => 'Deskripsi issue wajib diisi.',
            'platform.required' => 'Pilih platform (Website atau Mobile App).',
            'reporter_name.required' => 'Nama pelapor wajib diisi.',
            'attachments.*.max' => 'Ukuran file maksimal 10MB.',
        ];
    }
}
