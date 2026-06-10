<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SplitItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_item_id'   => 'required|exists:product_variants,id',
            'target_item_id'   => 'required|exists:product_variants,id|different:source_item_id',
            'location_id'      => 'required|exists:locations,id',
            'bin_id'           => 'nullable|exists:location_bins,id',
            'qty_to_split'     => 'required|integer|min:1',
            'split_into_qty'   => 'required|integer|min:1',
        ];
    }
}
