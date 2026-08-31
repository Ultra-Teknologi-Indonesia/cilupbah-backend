<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class MonitorStockExportRequest extends FormRequest
{
    private const TABS = [
        'stok-kosong',
        'menipis',
        'tidak-laku',
        'paling-laku',
        'perkiraan-habis',
        'sedang-dibeli',
        'gagal-sync',
        'kronologi',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'format' => ['required', Rule::in(['xlsx', 'pdf'])],
            'tab' => ['required', Rule::in(self::TABS)],
            'mode' => ['nullable', Rule::in(['habis', 'minus', 'dipesan'])],
            'kronologi_view' => ['nullable', Rule::in(['clean', 'attention', 'all'])],
            'search' => ['nullable', 'string', 'max:150'],
            'location_id' => ['nullable', 'string', 'max:64'],
            'category_id' => ['nullable', 'string', 'max:64'],
            'period' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'kron_source' => ['nullable', 'string', 'max:100'],
            'kron_direction' => ['nullable', Rule::in(['in', 'out'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'search' => $this->filled('search') ? trim((string) $this->input('search')) : null,
        ]);
    }
}
