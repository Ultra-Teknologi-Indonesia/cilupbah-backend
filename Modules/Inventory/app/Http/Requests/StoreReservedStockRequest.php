<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Warehouse\Models\Location;

class StoreReservedStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'location_id' => 'required|string|exists:locations,id',
            'is_active' => 'boolean',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|string|exists:product_variants,id',
            'items.*.bin_id' => 'nullable|string|exists:location_bins,id',
            'items.*.qty' => 'required|integer|min:1',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $locationId = $this->input('location_id');

            if (! is_string($locationId) || ! Location::isCentralWarehouseId($locationId)) {
                return;
            }

            $validator->errors()->add(
                'location_id',
                'Reservasi stok tidak dapat dibuat di Gudang Pusat.',
            );
        });
    }
}
