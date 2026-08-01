<?php

namespace Modules\Report\Http\Requests;

class PickListDetailExcelRequest extends PickListDetailPdfRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['download']);

        return $rules;
    }
}
