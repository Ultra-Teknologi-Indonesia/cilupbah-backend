<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProcessPutawayItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'destination_bin_id' => 'required|string|exists:location_bins,id',
            'qty' => 'required|integer|min:1',
        ];
    }
}
