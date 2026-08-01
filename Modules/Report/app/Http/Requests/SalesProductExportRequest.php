<?php

namespace Modules\Report\Http\Requests;

class SalesProductExportRequest extends DateRangeExportRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), $this->itemIdsRules());
    }
}
