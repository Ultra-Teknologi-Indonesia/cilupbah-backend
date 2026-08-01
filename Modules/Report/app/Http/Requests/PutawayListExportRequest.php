<?php

namespace Modules\Report\Http\Requests;

class PutawayListExportRequest extends PutawayListPdfRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['download']);

        return $rules;
    }
}
