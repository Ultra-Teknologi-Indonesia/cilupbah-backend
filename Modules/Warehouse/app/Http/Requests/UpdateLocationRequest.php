<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('location');

        return [
            'location_code' => "nullable|string|max:50|unique:locations,location_code,{$id}",
            'location_name' => 'nullable|string|max:255',
            'location_type' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'village_id' => 'nullable|string|exists:villages,id',
            'post_code' => 'nullable|string|max:20',
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^\+[1-9][0-9]{6,14}$/'],
            'email' => 'nullable|email|max:255',
            'coordinate' => 'nullable|string|max:100',
            'is_warehouse' => 'nullable|boolean',
            'is_small_warehouse' => 'nullable|boolean',
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
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Format No. Telepon tidak valid. Gunakan format internasional, contoh: +628123456789.',
        ];
    }
}
