<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTransferDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_location_id' => 'nullable|uuid|exists:locations,id',
            'destination_location_id' => 'nullable|uuid|exists:locations,id',
            'notes' => 'nullable|string',
        ];
    }
}
