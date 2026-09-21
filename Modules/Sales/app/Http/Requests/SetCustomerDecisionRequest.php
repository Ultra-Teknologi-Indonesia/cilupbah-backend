<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetCustomerDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => 'required|string|in:waiting,cancel,replace',
            'note'     => 'nullable|string|max:500',
            'replacement_sku' => 'nullable|required_if:decision,replace|string|max:100',
            'replacement_item_id' => 'nullable|required_if:decision,replace|uuid',
        ];
    }
}
