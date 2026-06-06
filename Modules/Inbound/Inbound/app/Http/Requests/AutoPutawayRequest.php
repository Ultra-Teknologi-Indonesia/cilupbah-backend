<?php

namespace Modules\Inbound\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AutoPutawayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'inbound_ids' => ['required', 'array'],
            'inbound_ids.*' => ['integer'],
            'created_by' => ['required', 'string', 'max:100'],
            'putaway_items' => ['required', 'array', 'min:1'],
            'putaway_items.*.item_id' => ['required', 'integer'],
            'putaway_items.*.destination_bin_id' => ['required', 'uuid', 'exists:location_bins,id'],
            'putaway_items.*.qty' => ['required', 'integer', 'min:1'],
            'putaway_items.*.batch_no' => ['nullable', 'string', 'max:100'],
            'putaway_items.*.serial_no' => ['nullable', 'string', 'max:100'],
        ];
    }
}
