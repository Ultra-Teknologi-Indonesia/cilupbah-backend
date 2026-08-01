<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTransferDraftItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'qty' => 'required|integer|min:1',
            'source_bin_id' => 'nullable|uuid|exists:location_bins,id',
        ];
    }
}
