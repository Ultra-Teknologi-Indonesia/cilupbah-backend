<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransferOutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_location_id'          => ['required', 'string', 'exists:locations,id'],
            'destination_location_id'     => ['required', 'string', 'exists:locations,id', 'different:source_location_id'],
            'notes'                       => ['nullable', 'string'],
            'created_by'                  => ['required', 'string', 'max:100'],
            'items'                       => ['required', 'array', 'min:1'],
            'items.*.item_id'             => ['required', 'string', 'size:32', 'exists:product_variants,id'],
            'items.*.qty'                 => ['required', 'integer', 'min:1'],
            'items.*.source_bin_id'       => ['nullable', 'string', 'size:32', 'exists:location_bins,id'],
            'items.*.destination_bin_id'  => ['nullable', 'string', 'size:32', 'exists:location_bins,id'],
            'items.*.batch_no'            => ['nullable', 'string', 'max:100'],
            'items.*.serial_no'           => ['nullable', 'string', 'max:100'],
        ];
    }
}
