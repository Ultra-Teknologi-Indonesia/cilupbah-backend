<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MoveSkuLocationBinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_id' => 'required|uuid|exists:product_variants,id',
            'destination_bin_id' => 'required|uuid|exists:location_bins,id',
        ];
    }
}
