<?php

namespace Modules\Report\Http\Requests;

class ShipmentByCourierExportRequest extends ShipmentByCourierPdfRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['download']);

        return $rules;
    }
}
