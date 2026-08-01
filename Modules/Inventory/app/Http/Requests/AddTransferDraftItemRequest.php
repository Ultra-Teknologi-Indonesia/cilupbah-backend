<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddTransferDraftItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_id' => 'required|uuid|exists:product_variants,id',
            'qty' => 'required|integer|min:1',
            'source_bin_id' => 'nullable|uuid|exists:location_bins,id',
            'destination_bin_id' => 'nullable|uuid|exists:location_bins,id',
            'batch_no' => 'nullable|string|max:100',
            'serial_no' => 'nullable|string|max:100',
        ];
    }
}
