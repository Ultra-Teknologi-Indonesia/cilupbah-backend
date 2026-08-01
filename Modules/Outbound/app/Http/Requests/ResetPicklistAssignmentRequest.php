<?php

namespace Modules\Outbound\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResetPicklistAssignmentRequest extends FormRequest
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
}
