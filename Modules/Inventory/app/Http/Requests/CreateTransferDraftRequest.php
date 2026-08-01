<?php

namespace Modules\Inventory\Http\Requests;

use App\Support\WarehouseAccess;
use Illuminate\Foundation\Http\FormRequest;

class CreateTransferDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        WarehouseAccess::assert($this->input('source_location_id'));
        WarehouseAccess::assert($this->input('destination_location_id'));

        return true;
    }

    public function rules(): array
    {
        return [
            'created_by' => 'required|string|max:100',
            'source_location_id' => 'nullable|uuid|exists:locations,id',
            'destination_location_id' => 'nullable|uuid|exists:locations,id',
            'notes' => 'nullable|string',
            'transfer_number' => 'nullable|string|max:100|unique:inventory_transfers,transfer_number',
        ];
    }
}
