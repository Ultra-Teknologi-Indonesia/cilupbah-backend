<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class OrderAuditReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:128'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'filter.channel' => ['nullable', 'in:shopee,tiktok,lazada,woocommerce'],
            'filter.shop_id' => ['nullable', 'string', 'max:128'],
            'filter.status' => ['nullable', 'in:match,missing,status_mismatch'],
            'filter.date_from' => ['nullable', 'date_format:Y-m-d'],
            'filter.date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:filter.date_from'],
        ];
    }
}
