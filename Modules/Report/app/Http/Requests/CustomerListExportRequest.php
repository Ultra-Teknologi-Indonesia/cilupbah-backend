<?php

namespace Modules\Report\Http\Requests;

class CustomerListExportRequest extends DateRangeExportRequest
{
    public function rules(): array
    {
        return $this->dateRules();
    }
}
