<?php

namespace Modules\Inbound\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResetInboundAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason_note' => ['required', 'string', 'min:10', 'max:500'],
            'new_assignee_id' => ['nullable', 'string', 'exists:users,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason_note.required' => 'Alasan reset wajib diisi (minimal 10 karakter).',
            'reason_note.min' => 'Alasan reset minimal 10 karakter.',
        ];
    }
}
