<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReceiveBinTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notes' => 'nullable|string',
            'received_by' => 'nullable|string|max:100',
            'items' => 'required|array|min:1',
            'items.*.bin_transfer_item_id' => 'required|uuid|exists:bin_transfer_items,id',
            'items.*.destination_bin_id' => 'required|uuid|exists:location_bins,id',
            'items.*.qty' => 'required|integer|min:1',
        ];
    }
}
