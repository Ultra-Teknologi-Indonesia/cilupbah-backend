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
            'source_location_id'          => ['required', 'integer', 'exists:locations,id'],
            'destination_location_id'     => ['required', 'integer', 'exists:locations,id', 'different:source_location_id'],
            'notes'                       => ['nullable', 'string'],
            'created_by'                  => ['required', 'string', 'max:100'],
            'items'                       => ['required', 'array', 'min:1'],
            'items.*.item_id'             => ['required', 'integer', 'exists:products,id'],
            'items.*.qty'                 => ['required', 'integer', 'min:1'],
            'items.*.source_bin_id'       => ['nullable', 'integer', 'exists:location_bins,id'],
            'items.*.destination_bin_id'  => ['nullable', 'integer', 'exists:location_bins,id'],
            'items.*.batch_no'            => ['nullable', 'string', 'max:100'],
            'items.*.serial_no'           => ['nullable', 'string', 'max:100'],
        ];
    }
}
