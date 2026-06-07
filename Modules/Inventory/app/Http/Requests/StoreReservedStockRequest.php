<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
}
