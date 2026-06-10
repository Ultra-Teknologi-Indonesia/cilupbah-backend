<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStockRevaluationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'location_id'        => 'required|exists:locations,id',
            'notes'              => 'nullable|string|max:1000',
            'items'              => 'required|array|min:1',
            'items.*.item_id'    => 'required|exists:product_variants,id',
            'items.*.bin_id'     => 'nullable|exists:location_bins,id',
            'items.*.new_cost'   => 'required|numeric|min:0',
        ];
    }
}
