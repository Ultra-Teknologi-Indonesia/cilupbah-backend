<?php

namespace Modules\Report\Http\Requests;

class PutawayPerformanceExportRequest extends PutawayPerformancePdfRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['download']);

        return $rules;
    }
}
