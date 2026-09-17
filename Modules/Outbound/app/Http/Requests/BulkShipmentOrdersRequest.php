<?php

namespace Modules\Outbound\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkShipmentOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'shipment_ids' => ['required', 'array', 'min:1', 'max:500'],
            'shipment_ids.*' => ['required', 'bail', 'uuid', 'distinct'],
        ];
    }
}
