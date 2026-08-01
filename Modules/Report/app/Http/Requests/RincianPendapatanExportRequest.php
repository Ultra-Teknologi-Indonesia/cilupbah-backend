<?php

namespace Modules\Report\Http\Requests;

use Illuminate\Validation\Rule;

class RincianPendapatanExportRequest extends DateRangeExportRequest
{
    public function rules(): array
    {
        return array_merge($this->dateRules(), $this->itemIdsRules(), [
            'jenis' => ['nullable', Rule::in(['rincian', 'per_barang'])],
        ]);
    }
}
