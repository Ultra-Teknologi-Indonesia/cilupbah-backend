<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExportSalesOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tab'         => 'nullable|string|max:30',
            'date_from'   => 'nullable|date',
            'date_to'     => 'nullable|date|after_or_equal:date_from',
            'source'      => 'nullable|string|max:50',
            'search'      => 'nullable|string|max:200',
            'store_id'    => 'nullable|uuid',
            'location_id' => 'nullable|uuid',
        ];
    }
}
