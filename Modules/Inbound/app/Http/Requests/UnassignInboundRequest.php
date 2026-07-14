<?php

namespace Modules\Inbound\Http\Requests;

use App\Enums\UnassignReasonEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UnassignInboundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason_code' => ['required', Rule::in(array_map(fn ($c) => $c->value, UnassignReasonEnum::userSelectable()))],
            'reason_note' => ['nullable', 'string', 'max:500'],
            'new_assignee_id' => ['nullable', 'string', 'exists:users,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason_code.required' => 'Alasan wajib diisi.',
            'reason_code.in' => 'Alasan tidak valid.',
        ];
    }
}
