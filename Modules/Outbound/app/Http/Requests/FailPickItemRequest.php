<?php

namespace Modules\Outbound\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FailPickItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason_code' => 'required|string|in:STOCK_EMPTY,DAMAGED,REJECTED,MISSING,OTHER',
            'reason_note' => 'nullable|string|max:500|required_if:reason_code,OTHER|min:5',
        ];
    }

    public function messages(): array
    {
        return [
            'reason_note.required_if' => 'Catatan wajib diisi untuk alasan Lainnya (minimal 5 karakter).',
            'reason_note.min' => 'Catatan minimal 5 karakter.',
        ];
    }
}
