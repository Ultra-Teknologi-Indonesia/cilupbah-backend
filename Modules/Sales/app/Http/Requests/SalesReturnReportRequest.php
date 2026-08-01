<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sales\Models\SalesReturn;

class SalesReturnReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_from'            => 'nullable|date',
            'date_to'             => 'nullable|date|after_or_equal:date_from',
            'location_id'         => 'nullable|string',
            'channel_shop_id'     => 'nullable|string',
            'status'              => 'nullable|string|max:20',
            'source'              => 'nullable|in:manual,marketplace',
            'reason_category'     => ['nullable', 'string', Rule::in(SalesReturn::REASON_CATEGORIES)],
            'marketplace_decision' => ['nullable', 'string', Rule::in(SalesReturn::MP_DECISIONS)],
            'per_page'            => 'nullable|integer|min:1|max:200',
        ];
    }
}
