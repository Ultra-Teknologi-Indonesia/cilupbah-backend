<?php

namespace Modules\Report\Http\Requests;

use Illuminate\Validation\Rule;

class ModeDateRangeRequest extends DateRangeExportRequest
{
    public function rules(): array
    {
        return array_merge($this->dateRules(required: true), $this->locationIdsRules(), [
            'mode' => ['required', Rule::in(['detail', 'summary'])],
            'download' => ['nullable', 'boolean'],
        ]);
    }
}
