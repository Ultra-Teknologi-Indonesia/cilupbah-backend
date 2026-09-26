<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class OrderAuditReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:128'],
            'per_page' => ['nullable', 'integer', Rule::in([20, 50, 100, 200])],
            'page' => ['nullable', 'integer', 'min:1'],
            'filter.channel' => ['nullable', 'in:shopee,tiktok,lazada,woocommerce'],
            'filter.shop_id' => ['nullable', 'string', 'max:128'],
            'filter.status' => ['nullable', 'in:match,missing,status_mismatch'],
            'filter.inbox_status' => ['nullable', 'in:received,processed,failed,skipped'],
            'filter.date_from' => ['nullable', 'date_format:Y-m-d'],
            'filter.date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:filter.date_from'],
        ];
    }
}
