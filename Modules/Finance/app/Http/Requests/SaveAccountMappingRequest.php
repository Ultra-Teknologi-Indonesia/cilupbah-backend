<?php

namespace Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Finance\Support\AccountMappingKey;

class SaveAccountMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mappings' => 'required|array|min:1',
            'mappings.*.key' => ['required', 'string', Rule::in(AccountMappingKey::keys())],
            'mappings.*.account_id' => ['nullable', 'uuid', Rule::exists('accounts', 'id')],
        ];
    }
}
