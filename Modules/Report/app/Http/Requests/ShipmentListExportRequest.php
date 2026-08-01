<?php

namespace Modules\Report\Http\Requests;

class ShipmentListExportRequest extends DateRangeExportRequest
{
    public function rules(): array
    {
        return array_merge($this->dateRules(required: true), [
            'courier_ids' => ['nullable', 'array'],
            'courier_ids.*' => ['uuid'],
            'status_mp' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
