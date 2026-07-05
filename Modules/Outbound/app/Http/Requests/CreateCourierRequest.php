<?php

namespace Modules\Outbound\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateCourierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:50|unique:couriers,code',
            'tracking_url' => 'nullable|string|max:500',
            'logo_url' => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
            'supported_shipment_types' => 'nullable|array',
            'supported_shipment_types.*' => 'string|in:REGULAR,EXPRESS,SAME_DAY,CARGO',
            'metadata' => 'nullable|array',
        ];
    }
}
