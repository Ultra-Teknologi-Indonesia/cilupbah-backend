<?php

namespace Modules\Outbound\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDriverCallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'driver_name' => 'nullable|string|max:100',
            'driver_phone' => 'nullable|string|max:30',
            'driver_vehicle_plate' => 'nullable|string|max:20',
            'driver_booking_code' => 'nullable|string|max:100',
            'driver_call_status' => 'nullable|string|in:CALLED,PICKED_UP,FAILED',
            'driver_id_card' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'shipper_id' => 'nullable|integer|exists:users,id',
        ];
    }
}
