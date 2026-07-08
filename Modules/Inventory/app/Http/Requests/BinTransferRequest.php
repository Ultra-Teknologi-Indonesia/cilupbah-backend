<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Warehouse\Models\LocationBin;

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
            'transfer_number' => 'nullable|string|max:50|unique:bin_transfers,transfer_number',
            'transfer_date' => 'nullable|date',
            'created_by' => 'required|string|max:100',
            'notes' => 'nullable|string',

            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|uuid|exists:product_variants,id',
            'items.*.source_bin_id' => 'required|uuid|exists:location_bins,id',
            // Rak tujuan ditentukan saat penerimaan (langkah Selesai), tak wajib saat buat draft.
            'items.*.destination_bin_id' => 'nullable|uuid|different:items.*.source_bin_id|exists:location_bins,id',
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
            'transfer_number.unique' => 'No. transfer internal sudah dipakai.',
            'items.required' => 'Minimal 1 produk harus ditransfer.',
            'items.min' => 'Minimal 1 produk harus ditransfer.',
            'items.*.source_bin_id.required' => 'Rak asal wajib diisi.',
            'items.*.destination_bin_id.required' => 'Rak tujuan wajib diisi.',
            'items.*.destination_bin_id.different' => 'Rak asal dan rak tujuan harus berbeda.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $locationId = $this->input('location_id');
            $items = $this->input('items', []);
            if (! $locationId || ! is_array($items)) {
                return;
            }

            $binIds = [];
            foreach ($items as $it) {
                if (! empty($it['source_bin_id'])) {
                    $binIds[$it['source_bin_id']] = true;
                }
                if (! empty($it['destination_bin_id'])) {
                    $binIds[$it['destination_bin_id']] = true;
                }
            }
            if (empty($binIds)) {
                return;
            }

            $binLocations = LocationBin::whereIn('id', array_keys($binIds))
                ->pluck('location_id', 'id')
                ->all();

            foreach ($items as $idx => $it) {
                $srcId = $it['source_bin_id'] ?? null;
                $destId = $it['destination_bin_id'] ?? null;

                if ($srcId && isset($binLocations[$srcId]) && $binLocations[$srcId] !== $locationId) {
                    $v->errors()->add(
                        "items.$idx.source_bin_id",
                        'Rak asal harus milik lokasi yang dipilih.',
                    );
                }
                if ($destId && isset($binLocations[$destId]) && $binLocations[$destId] !== $locationId) {
                    $v->errors()->add(
                        "items.$idx.destination_bin_id",
                        'Rak tujuan harus milik lokasi yang dipilih.',
                    );
                }
            }
        });
    }
}
