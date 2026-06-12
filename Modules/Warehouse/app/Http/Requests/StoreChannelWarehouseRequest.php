<?php

namespace Modules\Warehouse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreChannelWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'location_id' => 'required|bail|uuid|exists:locations,id',
            'channel_id' => 'required|bail|uuid|exists:channels,id',
            'store_id' => 'required|string|max:255',
            'channel_location_id' => 'required|string|max:255',
            'channel_location_type' => 'nullable|string|max:50',
        ];
    }
}
