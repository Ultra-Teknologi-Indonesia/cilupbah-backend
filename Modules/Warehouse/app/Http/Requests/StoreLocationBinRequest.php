<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLocationBinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'location_id' => 'required|exists:locations,id',
            'zone_id' => 'nullable|exists:location_zones,id',
            'floor_code' => 'required|string|max:10',
            'row_code' => 'required|string|max:10',
            'column_code' => 'required|string|max:10',
            'bin_code' => 'required|string|max:10',
            'max_qty' => 'nullable|numeric|min:0',
            'is_inbound' => 'nullable|boolean',
        ];
    }
}
