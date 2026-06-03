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
            'floor_code' => 'nullable|string|max:20',
            'row_code' => 'nullable|string|max:20',
            'column_code' => 'nullable|string|max:20',
            'bin_code' => 'nullable|string|max:20',
            'max_qty' => 'nullable|integer|min:0',
            'is_inbound' => 'nullable|boolean',
        ];
    }
}
