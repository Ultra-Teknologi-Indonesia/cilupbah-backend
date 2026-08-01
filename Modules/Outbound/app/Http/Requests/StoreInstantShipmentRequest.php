<?php

namespace Modules\Outbound\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInstantShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'location_id' => 'required|string|exists:locations,id',
            'shipment_date' => 'required|date',
            'courier_name' => 'nullable|string|max:100',
            'courier_code' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:500',
            'shipper_id' => 'nullable|integer|exists:users,id',
        ];
    }
}
