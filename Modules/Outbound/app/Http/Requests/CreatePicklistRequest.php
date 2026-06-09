<?php

namespace Modules\Outbound\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreatePicklistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'string|exists:sales_orders,id',
            'location_id' => 'required|string|exists:locations,id',
            'picker_id' => 'nullable|string|exists:users,id',
            'notes' => 'nullable|string|max:500',
        ];
    }
}
