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

        return [
            'format' => ['sometimes', 'string', 'in:csv'],
        ];
    }
}
