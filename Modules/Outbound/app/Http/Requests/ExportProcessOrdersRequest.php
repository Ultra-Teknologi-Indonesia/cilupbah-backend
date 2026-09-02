<?php

declare(strict_types=1);

namespace Modules\Outbound\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Outbound\Support\ActiveProcessOrderScope;

final class ExportProcessOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'format' => ['sometimes', 'string', 'in:csv'],
            'stage' => ['required', 'string', 'in:picking,packing,shipping'],
            'sub' => ['required', 'string'],
        ];
    }

    protected function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $stage = (string) $this->input('stage');
            $sub = (string) $this->input('sub');

            if ($stage !== '' && $sub !== '' && ! ActiveProcessOrderScope::isAllowed($stage, $sub)) {
                $validator->errors()->add('sub', 'Sub-status tidak valid untuk proses yang dipilih.');
            }
        });
    }
}
