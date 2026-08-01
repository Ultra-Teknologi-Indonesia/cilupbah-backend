<?php

namespace Modules\Report\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DateRangeExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function dateRules(bool $required = false): array
    {
        $rule = $required ? 'required' : 'nullable';

        return [
            'from' => [$rule, 'date'],
            'to' => [$rule, 'date', 'after_or_equal:from'],
        ];
    }

    protected function locationIdsRules(): array
    {
        return [
            'location_ids' => ['nullable', 'array'],
            'location_ids.*' => ['uuid'],
        ];
    }

    protected function itemIdsRules(): array
    {
        return [
            'item_ids' => ['nullable', 'array'],
            'item_ids.*' => ['uuid'],
        ];
    }

    public function rules(): array
    {
        return array_merge($this->dateRules(), $this->locationIdsRules());
    }
}
