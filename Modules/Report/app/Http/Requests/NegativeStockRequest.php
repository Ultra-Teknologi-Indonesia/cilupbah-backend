<?php

namespace Modules\Report\Http\Requests;

class NegativeStockRequest extends DateRangeExportRequest
{
    public function rules(): array
    {
        return array_merge($this->dateRules(), [
            'location_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:200'],
            'still_negative' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
    }
}
