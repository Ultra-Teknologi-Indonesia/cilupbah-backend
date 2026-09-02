<?php

declare(strict_types=1);

namespace Modules\Dashboard\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class DashboardSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'location_id' => ['nullable', 'uuid', 'exists:locations,id'],
        ];
    }
}
