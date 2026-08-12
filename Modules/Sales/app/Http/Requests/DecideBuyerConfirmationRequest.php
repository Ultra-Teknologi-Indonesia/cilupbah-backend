<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sales\Models\OrderBuyerConfirmation;

class DecideBuyerConfirmationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outcome' => ['required', Rule::in(OrderBuyerConfirmation::OUTCOMES)],
            'replacement_sku' => [
                Rule::requiredIf(fn () => $this->input('outcome') === OrderBuyerConfirmation::OUTCOME_REPLACE),
                'nullable',
                'string',
                'exists:product_variants,sku',
            ],
            'note' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'replacement_sku.required' => 'SKU pengganti wajib diisi.',
            'replacement_sku.exists' => 'SKU pengganti tidak ditemukan di master produk.',
        ];
    }
}
