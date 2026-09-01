<?php

declare(strict_types=1);

namespace Modules\Outbound\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ExportProcessOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Export Proses Pesanan intentionally has no stage selector: it always
        // exports every current process status in one file.
        return [
            'format' => ['sometimes', 'string', 'in:csv'],
        ];
    }
}
