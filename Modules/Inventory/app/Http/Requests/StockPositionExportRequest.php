<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StockPositionExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'format' => ['required', Rule::in(['csv', 'pdf'])],
            'search' => ['nullable', 'string', 'max:150'],
            'sort' => ['nullable', Rule::in([
                'item_code', '-item_code',
                'average_cost', '-average_cost',
                'on_hand', '-on_hand',
                'available', '-available',
            ])],
            'is_bundle' => ['nullable', Rule::in(['0', '1', 0, 1, true, false])],
            'channel' => ['nullable', 'string', 'max:100'],
            'visible_location_ids' => ['present', 'array', 'max:100'],
            'visible_location_ids.*' => ['uuid'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [
            'search' => $this->filled('search') ? trim((string) $this->input('search')) : null,
            'channel' => $this->filled('channel') ? trim((string) $this->input('channel')) : null,
        ];

        if ($this->has('visible_location_ids')) {
            $normalized['visible_location_ids'] = array_values(array_unique(array_filter(
                (array) $this->input('visible_location_ids', []),
                static fn ($id): bool => is_string($id) && trim($id) !== '',
            )));
        }

        $this->merge($normalized);
    }
}
