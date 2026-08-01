<?php

namespace Modules\Report\Http\Requests;

class NegativeStockExportRequest extends NegativeStockRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['per_page']);

        return $rules;
    }
}
