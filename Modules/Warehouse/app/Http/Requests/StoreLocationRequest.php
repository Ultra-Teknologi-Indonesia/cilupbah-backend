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
            'area' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'province' => 'nullable|string|max:100',
            'post_code' => 'nullable|string|max:20',
            'is_warehouse' => 'nullable|boolean',
            'is_multi_origin' => 'nullable|boolean',
            'default_warehouse_user' => 'nullable|string|max:255|email',
            'is_active' => 'nullable|boolean',
            'is_fbl' => 'nullable|boolean',
            'is_tcb' => 'nullable|boolean',
            'is_fbs' => 'nullable|boolean',
        ];
    }
}
