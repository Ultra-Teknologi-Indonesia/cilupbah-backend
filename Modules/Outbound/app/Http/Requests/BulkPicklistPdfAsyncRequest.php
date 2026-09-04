<?php

namespace Modules\Outbound\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkPicklistPdfAsyncRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $raw = $this->input('order_ids', []);
        $raw = is_array($raw) ? $raw : [$raw];
        $ids = collect($raw)->flatMap(static fn ($value): array => explode(',', rawurldecode(trim((string) $value))))
            ->map(static fn ($id): string => trim((string) $id))->filter()->unique()->values()->all();
        $this->merge(['order_ids' => $ids]);
    }

    public function rules(): array
    {
        return ['order_ids' => ['required', 'array', 'min:1'], 'order_ids.*' => ['required', 'uuid']];
    }
}
