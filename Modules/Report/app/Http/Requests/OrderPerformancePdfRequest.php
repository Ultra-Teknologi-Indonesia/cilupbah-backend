<?php

namespace Modules\Report\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Report\Support\OrderPerformanceSpec;

class OrderPerformancePdfRequest extends DateRangeExportRequest
{
    public function rules(): array
    {
        return array_merge($this->dateRules(required: true), $this->locationIdsRules(), [
            'jenis' => ['required', Rule::in(OrderPerformanceSpec::TYPES)],
            'mode' => ['required', Rule::in(['detail', 'summary'])],
            'download' => ['nullable', 'boolean'],
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->input('mode') === 'summary' && ! OrderPerformanceSpec::supportsSummary((string) $this->input('jenis'))) {
                $validator->errors()->add('mode', 'Laporan Pesanan hanya tersedia dalam mode Detail.');
            }
        });
    }
}
