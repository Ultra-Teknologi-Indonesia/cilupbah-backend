<?php

namespace Modules\Inbound\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Inbound\Models\Inbound;

class StoreInboundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $types = implode(',', Inbound::TYPES);

        return [
            'location_id'       => ['required', 'string', 'exists:locations,id'],
            'reference_number'  => ['nullable', 'string', 'max:100'],
            'type'              => ['required', "in:{$types}"],
            'source_type'       => ['nullable', 'string', 'max:50'],
            'source_id'         => ['nullable', 'string'],
            'expected_date'     => ['required', 'date'],
            'created_by'        => ['required', 'string', 'max:100'],
            'items'             => ['required', 'array', 'min:1'],
            'items.*.item_id'   => ['required', 'string', 'uuid'],
            'items.*.expected_qty' => ['required', 'integer', 'min:1'],
        ];
    }
}
