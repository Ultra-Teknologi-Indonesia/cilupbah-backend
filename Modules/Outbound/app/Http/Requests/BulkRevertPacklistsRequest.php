<?php

namespace Modules\Outbound\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkRevertPacklistsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'packlist_ids' => ['required', 'array', 'min:1', 'max:100'],
            'packlist_ids.*' => ['required', 'string', 'distinct', 'exists:packlists,id'],
        ];
    }
}
