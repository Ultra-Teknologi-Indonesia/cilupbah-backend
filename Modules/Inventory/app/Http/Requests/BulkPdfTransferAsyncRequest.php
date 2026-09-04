<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkPdfTransferAsyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $rawIds = $this->input('ids', []);
        $rawIds = is_array($rawIds) ? $rawIds : [$rawIds];

        $ids = collect($rawIds)
            ->flatMap(static function ($value): array {
                $decoded = rawurldecode(trim((string) $value));

                return explode(',', $decoded);
            })
            ->map(static fn ($id): string => trim((string) $id))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $this->merge(['ids' => $ids]);
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'uuid'],
        ];
    }
}
