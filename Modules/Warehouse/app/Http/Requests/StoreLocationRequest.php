<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'location_code' => 'required|string|max:50|unique:locations,location_code',
            'location_name' => 'required|string|max:255',
            'location_type' => 'required|string|max:50',
            'address' => 'nullable|string',
            'village_id' => 'nullable|string|exists:villages,id',
            'post_code' => 'nullable|string|max:20',
            'is_warehouse' => 'nullable|boolean',
            'is_multi_origin' => 'nullable|boolean',
            'default_warehouse_user' => 'nullable|string|max:255|email',
            'is_active' => 'nullable|boolean',
            'is_fbl' => 'nullable|boolean',
            'is_tcb' => 'nullable|boolean',
            'is_fbs' => 'nullable|boolean',
            'is_pos' => 'nullable|boolean',
            'layout' => 'nullable|array',
            'layout.*.zone_code' => 'required_with:layout|string|max:20',
            'layout.*.zone_name' => 'nullable|string|max:100',
            'layout.*.racks' => 'required_with:layout|array',
            'layout.*.racks.*.floor_code' => 'required|string|max:20',
            'layout.*.racks.*.row_code' => 'required|string|max:20',
            'layout.*.racks.*.column_code' => 'required|string|max:20',
            'layout.*.racks.*.bin_code' => 'required|string|max:20',
            'layout.*.racks.*.bin_final_code' => 'required|string|max:100',
            'layout.*.racks.*.max_qty' => 'nullable|integer|min:0',
        ];
    }
}
