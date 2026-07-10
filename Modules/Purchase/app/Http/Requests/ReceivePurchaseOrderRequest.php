<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReceivePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reference_number'                  => ['nullable', 'string', 'max:255'],
            'location_id'                       => ['nullable', 'string', 'exists:locations,id'],
            'receive_date'                      => ['nullable', 'date'],
            'notes'                             => ['nullable', 'string'],
            'items'                             => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id'    => ['required', 'string', 'exists:purchase_order_items,id'],

            'items.*.qty'                       => ['required', 'integer', 'min:0'],
            'items.*.rejected_qty'              => ['nullable', 'integer', 'min:0'],
            'items.*.rejection_note'            => ['nullable', 'string'],
            'items.*.notes'                     => ['nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            foreach ((array) $this->input('items', []) as $index => $item) {
                $accepted = (int) ($item['qty'] ?? 0);
                $rejected = (int) ($item['rejected_qty'] ?? 0);
                if ($accepted + $rejected < 1) {
                    $validator->errors()->add(
                        "items.{$index}.qty",
                        'Jumlah diterima + ditolak minimal 1 untuk setiap item.'
                    );
                }
            }
        });
    }
}
