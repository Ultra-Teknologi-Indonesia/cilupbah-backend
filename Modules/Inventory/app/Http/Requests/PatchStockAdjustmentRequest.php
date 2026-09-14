<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PatchStockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'transaction_date' => 'nullable|date',
            'is_beginning_balance' => 'nullable|boolean',
            'notes' => 'nullable|string',
            'changes' => 'nullable|array',
            'changes.create' => 'nullable|array',
            'changes.create.*.item_id' => 'required|string|exists:product_variants,id',
            'changes.create.*.bin_id' => 'nullable|string|exists:location_bins,id',
            'changes.create.*.actual_qty' => 'required_without:changes.create.*.input_value|nullable|integer',
            'changes.create.*.mode' => 'nullable|string|in:DELTA,FINAL',
            'changes.create.*.input_value' => 'required_without:changes.create.*.actual_qty|nullable|integer',
            'changes.create.*.unit_cost' => 'nullable|numeric|min:0',
            'changes.create.*.notes' => 'nullable|string',
            'changes.update' => 'nullable|array',
            'changes.update.*.id' => 'required|string|exists:stock_adjustment_items,id',
            'changes.update.*.bin_id' => 'nullable|string|exists:location_bins,id',
            'changes.update.*.actual_qty' => 'required_without:changes.update.*.input_value|nullable|integer',
            'changes.update.*.mode' => 'nullable|string|in:DELTA,FINAL',
            'changes.update.*.input_value' => 'required_without:changes.update.*.actual_qty|nullable|integer',
            'changes.update.*.unit_cost' => 'nullable|numeric|min:0',
            'changes.update.*.notes' => 'nullable|string',
            'changes.delete_ids' => 'nullable|array',
            'changes.delete_ids.*' => 'required|string|distinct|exists:stock_adjustment_items,id',
        ];
    }
}
