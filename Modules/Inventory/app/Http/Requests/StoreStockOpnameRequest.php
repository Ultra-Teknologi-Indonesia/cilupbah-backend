<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStockOpnameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'location_id' => 'required|string|exists:locations,id',
            'filter_zone_id' => 'nullable|string|exists:location_zones,id',
            'filter_floor_codes' => 'nullable|array',
            'filter_floor_codes.*' => 'string',
            'filter_row_codes' => 'nullable|array',
            'filter_row_codes.*' => 'string',
            'filter_column_codes' => 'nullable|array',
            'filter_column_codes.*' => 'string',
            'notes' => 'nullable|string',
            'process_by' => 'nullable|string|max:100',
        ];
    }
}
