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
            'shipment_no' => 'nullable|string|max:50',
            'location_id' => 'required|string|exists:locations,id',
            'courier_name' => 'nullable|string|max:100',
            'courier_code' => 'nullable|string|max:50',
            'shipment_type' => 'required|string|in:REGULAR,EXPRESS,SAME_DAY,CARGO,INSTANT',
            'shipment_date' => 'required|date|after_or_equal:today',
            'notes' => 'nullable|string|max:500',
        ];
    }
}
