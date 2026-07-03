<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BinTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'location_id' => 'required|exists:locations,id',
            'source_bin_id' => 'required|uuid|exists:location_bins,id',
            'destination_bin_id' => 'required|uuid|different:source_bin_id|exists:location_bins,id',
            'transfer_number' => 'nullable|string|max:50|unique:bin_transfers,transfer_number',
            'transfer_date' => 'nullable|date',
            'created_by' => 'required|string|max:100',
            'notes' => 'nullable|string',

            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|uuid|exists:product_variants,id',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.batch_no' => 'nullable|string|max:100',
            'items.*.serial_no' => 'nullable|string|max:100',
            'items.*.expired_date' => 'nullable|date',
            'items.*.notes' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'destination_bin_id.different' => 'Rak asal dan rak tujuan harus berbeda.',
            'transfer_number.unique' => 'No. transfer internal sudah dipakai.',
            'items.required' => 'Minimal 1 produk harus ditransfer.',
            'items.min' => 'Minimal 1 produk harus ditransfer.',
        ];
    }
}
