<?php

namespace Modules\Outbound\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'shipment_no' => ['nullable', 'string', 'max:50', Rule::unique('shipments', 'shipment_no')],
            'location_id' => 'nullable|string|exists:locations,id',
            'courier_name' => 'nullable|string|max:100',
            'courier_code' => 'nullable|string|max:50',
            'shipment_type' => 'required|string|in:REGULAR,EXPRESS,SAME_DAY,CARGO,INSTANT',
            'shipment_date' => 'required|date|after_or_equal:today',
            'notes' => 'nullable|string|max:500',
            'shipper_id' => 'nullable|integer|exists:users,id',
        ];
    }

    public function messages(): array
    {
        return [
            'shipment_no.unique' => 'Nomor pengiriman sudah digunakan. Gunakan nomor lain atau kosongkan agar dibuat otomatis.',
            'location_id.exists' => 'Lokasi pengiriman tidak ditemukan.',
            'shipment_type.in' => 'Tipe pengiriman tidak valid.',
            'shipment_date.after_or_equal' => 'Tanggal pengiriman tidak boleh sebelum hari ini.',
        ];
    }
}
