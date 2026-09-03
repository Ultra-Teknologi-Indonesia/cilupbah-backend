<?php

declare(strict_types=1);

namespace Modules\Product\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Product\Models\Product;

final class ProductCatalogExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', 'string', Rule::in(Product::STATUSES)],
            'category_id' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', Rule::in(['satuan', 'bundle', 'konsinyasi', 'pre_order'])],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0', 'gte:min_price'],
            'channel' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'string', Rule::in([
                'name', '-name', 'created_at', '-created_at', 'updated_at', '-updated_at',
            ])],
            'product_ids' => ['nullable', 'array', 'max:5000'],
            'product_ids.*' => ['uuid', 'distinct'],
        ];
    }

    public function normalized(): array
    {
        $data = $this->validated();

        return [
            'search' => isset($data['search']) ? trim((string) $data['search']) : null,
            'status' => $data['status'] ?? Product::STATUS_MASTER,
            'category_id' => $data['category_id'] ?? null,
            'type' => $data['type'] ?? null,
            'min_price' => $data['min_price'] ?? null,
            'max_price' => $data['max_price'] ?? null,
            'channel' => $data['channel'] ?? null,
            'sort' => $data['sort'] ?? '-updated_at',
            'product_ids' => array_values($data['product_ids'] ?? []),
        ];
    }
}
