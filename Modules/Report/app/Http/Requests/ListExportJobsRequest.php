<?php

declare(strict_types=1);

namespace Modules\Report\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Report\Models\ExportJob;
use Modules\Report\Support\ExportCatalog;

final class ListExportJobsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $filter = $this->input('filter');
        $filter = is_array($filter) ? $filter : [];

        foreach (['search', 'category', 'type', 'status', 'format', 'created_from', 'created_to'] as $key) {
            if (! array_key_exists($key, $filter) && $this->has($key)) {
                $filter[$key] = $this->input($key);
            }
        }

        $payload = ['filter' => $filter];
        if ($this->has('direction') && $this->filled('sort')) {
            $sort = ltrim((string) $this->input('sort'), '-');
            $payload['sort'] = $this->input('direction') === 'desc' ? '-'.$sort : $sort;
        }

        $this->merge($payload);
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array'],
            'filter.search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'filter.category' => ['sometimes', 'nullable', Rule::in(['inventory', 'warehouse', 'sales', 'purchase', 'other'])],
            'filter.type' => ['sometimes', 'nullable', Rule::in(ExportCatalog::types())],
            'filter.status' => ['sometimes', 'nullable', Rule::in([
                ExportJob::STATUS_QUEUED,
                ExportJob::STATUS_PROCESSING,
                ExportJob::STATUS_READY,
                ExportJob::STATUS_FAILED,
                ExportJob::STATUS_EXPIRED,
            ])],
            'filter.format' => ['sometimes', 'nullable', Rule::in(['pdf', 'xlsx', 'csv'])],
            'filter.created_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'filter.created_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:filter.created_from'],
            'sort' => ['sometimes', 'nullable', 'string', Rule::in([
                'created_at',
                '-created_at',
                'finished_at',
                '-finished_at',
                'status',
                '-status',
                'type',
                '-type',
                'file_name',
                '-file_name',
                'file_purged_at',
                '-file_purged_at',
            ])],
            'direction' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', Rule::in([20, 50, 100, 200])],
        ];
    }

    public function filters(): array
    {
        return $this->validated()['filter'] ?? [];
    }
}
