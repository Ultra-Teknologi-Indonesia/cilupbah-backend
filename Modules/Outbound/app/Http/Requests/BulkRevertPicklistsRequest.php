<?php

namespace Modules\Outbound\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkRevertPicklistsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'picklist_ids' => ['required', 'array', 'min:1', 'max:100'],
            'picklist_ids.*' => ['required', 'string', 'distinct', 'exists:picklists,id'],
        ];
    }
}
