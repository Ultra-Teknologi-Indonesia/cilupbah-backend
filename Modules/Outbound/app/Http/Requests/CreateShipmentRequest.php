<?php

namespace Modules\Outbound\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'location_id' => 'required|string|exists:locations,id',
            'courier_name' => 'nullable|string|max:100',
            'courier_code' => 'nullable|string|max:50',
            'shipment_type' => 'required|string|in:REGULAR,EXPRESS,SAME_DAY,CARGO',
            'shipment_date' => 'required|date',
            'notes' => 'nullable|string|max:500',
        ];
    }
}
