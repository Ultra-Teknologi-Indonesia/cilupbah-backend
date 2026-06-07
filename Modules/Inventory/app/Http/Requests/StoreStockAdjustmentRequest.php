<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'transaction_date' => 'required|date',
            'location_id' => 'required|string|exists:locations,id',
            'is_beginning_balance' => 'boolean',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|string|exists:product_variants,id',
            'items.*.bin_id' => 'nullable|string|exists:location_bins,id',
            'items.*.actual_qty' => 'required|integer|min:0',
            'items.*.batch_no' => 'nullable|string|max:100',
            'items.*.serial_no' => 'nullable|string|max:100',
            'items.*.notes' => 'nullable|string',
        ];
    }
}
