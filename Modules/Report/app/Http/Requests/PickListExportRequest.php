<?php

namespace Modules\Report\Http\Requests;

class PickListExportRequest extends DateRangeExportRequest
{
    public function rules(): array
    {
        return $this->dateRules(required: true);
    }
}
