<?php

namespace Modules\Product\Imports;

class ProductRowsImport extends BaseRowsImport
{
    protected function rules(): array
    {
        return [
            'item_group_name' => ['required', 'string', 'max:255'],
            'item_code' => ['required', 'string', 'max:255'],
            'sell_price' => ['required', 'numeric', 'min:0'],
            'barcode' => ['nullable', 'string', 'max:255'],
            'item_category_id' => ['nullable', 'integer'],
            'package_weight' => ['nullable', 'numeric', 'min:0'],
            'package_length' => ['nullable', 'numeric', 'min:0'],
            'package_width' => ['nullable', 'numeric', 'min:0'],
            'package_height' => ['nullable', 'numeric', 'min:0'],
            'image_url1' => ['nullable', 'url'],
            'image_url2' => ['nullable', 'url'],
            'image_url3' => ['nullable', 'url'],
            'image_url4' => ['nullable', 'url'],
            'image_url5' => ['nullable', 'url'],
            'default_images' => ['nullable', 'url'],
        ];
    }

    protected function isEmptyRow(array $data): bool
    {
        return empty($data['item_group_name']) && empty($data['item_code']);
    }

    protected function process(array $data): void
    {
        $this->service->processSingleProductRow($data);
    }

    protected function validate(array $data): void
    {
        $this->service->validateSingleProductRow($data);
    }

    protected function extractSku(array $data): ?string
    {
        return $data['item_code'] ?? null;
    }

    protected function extractName(array $data): ?string
    {
        return $data['item_group_name'] ?? null;
    }

    protected function extractCategory(array $data): ?string
    {
        return $data['category'] ?? (! empty($data['item_category_id']) ? "Cat #{$data['item_category_id']}" : null);
    }

    protected function extractPrice(array $data): ?float
    {
        return isset($data['sell_price']) ? (float) $data['sell_price'] : null;
    }
}
