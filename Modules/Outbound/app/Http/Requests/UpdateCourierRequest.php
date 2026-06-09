<?php

namespace Modules\Outbound\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCourierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:100',
            'code' => ['sometimes', 'string', 'max:50', Rule::unique('couriers', 'code')->ignore($this->route('id'))],
            'type' => 'sometimes|string|in:REGULAR,EXPRESS,SAME_DAY,CARGO,INSTANT',
            'tracking_url' => 'nullable|string|max:500',
            'logo_url' => 'nullable|string|max:500',
            'is_active' => 'sometimes|boolean',
            'supported_shipment_types' => 'nullable|array',
            'supported_shipment_types.*' => 'string|in:REGULAR,EXPRESS,SAME_DAY,CARGO',
            'metadata' => 'nullable|array',
        ];
    }
}
