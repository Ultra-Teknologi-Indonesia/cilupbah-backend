<?php

declare(strict_types=1);

namespace Modules\Dashboard\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class DashboardQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'location_id' => ['nullable', 'uuid', 'exists:locations,id'],
        ];
    }
}
