<?php

namespace Modules\Report\Http\Requests;

use Illuminate\Validation\Rule;

class TransferExportRequest extends DateRangeExportRequest
{
    public function rules(): array
    {
        return array_merge($this->dateRules(required: true), $this->itemIdsRules(), [
            'jenis' => ['required', Rule::in(['masuk', 'keluar'])],
        ]);
    }
}
