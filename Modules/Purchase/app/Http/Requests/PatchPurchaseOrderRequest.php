<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PatchPurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = (string) $this->route('id');

        return [
            'po_number' => ['sometimes', 'nullable', 'string', 'max:50', "unique:purchase_orders,po_number,{$id}"],
            'contact_id' => ['sometimes', 'string', 'exists:contacts,id'],
            'location_id' => ['sometimes', 'string', 'exists:locations,id'],
            'order_date' => ['sometimes', 'date'],
            'expected_date' => ['sometimes', 'nullable', 'date'],
            'ref_no' => ['sometimes', 'nullable', 'string', 'max:100'],
            'payment_term' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_tax_included' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string'],

            'changes' => ['sometimes', 'array'],
            'changes.create' => ['sometimes', 'array', 'max:200'],
            'changes.create.*.item_id' => ['required', 'uuid', 'exists:product_variants,id'],
            'changes.create.*.description' => ['sometimes', 'nullable', 'string'],
            'changes.create.*.unit' => ['sometimes', 'nullable', 'string', 'max:30'],
            'changes.create.*.qty' => ['required', 'integer', 'min:1'],
            'changes.create.*.unit_price' => ['required', 'numeric', 'min:0'],
            'changes.create.*.disc' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'changes.create.*.shipping_cost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'changes.create.*.tax_id' => ['sometimes', 'nullable', 'uuid', 'exists:taxes,id'],

            'changes.update' => ['sometimes', 'array', 'max:200'],
            'changes.update.*.id' => ['required', 'uuid', 'distinct'],
            'changes.update.*.description' => ['sometimes', 'nullable', 'string'],
            'changes.update.*.unit' => ['sometimes', 'nullable', 'string', 'max:30'],
            'changes.update.*.qty' => ['sometimes', 'integer', 'min:1'],
            'changes.update.*.unit_price' => ['sometimes', 'numeric', 'min:0'],
            'changes.update.*.disc' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'changes.update.*.shipping_cost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'changes.update.*.tax_id' => ['sometimes', 'nullable', 'uuid', 'exists:taxes,id'],

            'changes.delete_ids' => ['sometimes', 'array', 'max:200'],
            'changes.delete_ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $data = $this->all();
            $hasHeader = collect([
                'po_number', 'contact_id', 'location_id', 'order_date', 'expected_date',
                'ref_no', 'payment_term', 'is_tax_included', 'notes',
            ])->contains(fn (string $key): bool => array_key_exists($key, $data));
            $hasChanges = collect($data['changes'] ?? [])->flatten(1)->isNotEmpty();

            if (! $hasHeader && ! $hasChanges) {
                $validator->errors()->add('changes', 'Tidak ada perubahan untuk disimpan.');
            }

            $updateIds = collect($data['changes']['update'] ?? [])->pluck('id')->filter();
            $deleteIds = collect($data['changes']['delete_ids'] ?? [])->filter();
            if ($updateIds->intersect($deleteIds)->isNotEmpty()) {
                $validator->errors()->add('changes', 'Satu baris tidak boleh diperbarui dan dihapus dalam perubahan yang sama.');
            }
        });
    }
}
