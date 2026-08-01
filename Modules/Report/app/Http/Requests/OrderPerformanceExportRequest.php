<?php

namespace Modules\Report\Http\Requests;

class OrderPerformanceExportRequest extends OrderPerformancePdfRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['download']);

        return $rules;
    }
}
