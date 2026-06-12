<?php

namespace Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi listing cashbank. Param tanggal mengikuti nama Jubelio
 * (transactionDateFrom/transactionDateTo) — format salah → 422, bukan 500.
 */
class CashbankIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'transactionDateFrom' => ['nullable', 'date'],
            'transactionDateTo' => ['nullable', 'date', 'after_or_equal:transactionDateFrom'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
