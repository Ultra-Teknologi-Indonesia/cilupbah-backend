<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UnassignPutawayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason_code' => ['required', 'string', 'in:SALAH_TAP,SHIFT_HABIS,SAKIT,KENDALA_TEKNIS,LAINNYA'],
            'reason_note' => ['nullable', 'string', 'max:500'],
            'new_assignee_id' => ['nullable', 'string', 'exists:users,id'],
        ];
    }
}
