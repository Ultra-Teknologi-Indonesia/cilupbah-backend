<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PutawayStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_id' => 'required|exists:products,id',
            'location_id' => 'required|exists:locations,id',
            'source_bin_id' => 'required|exists:location_bins,id',
            'destination_bin_id' => 'required|exists:location_bins,id',
            'batch_no' => 'nullable|string|max:100',
            'serial_no' => 'nullable|string|max:100',
            'expired_date' => 'nullable|date',
            'qty' => 'required|integer|min:1',
            'created_by' => 'required|string|max:100',
        ];
    }
}
