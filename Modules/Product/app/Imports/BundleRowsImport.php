<?php

namespace Modules\Product\Imports;

class BundleRowsImport extends BaseRowsImport
{
    protected function rules(): array
    {
        return [
            'item_code' => ['required', 'string', 'max:255'],
            'bundle_name' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'sell_price' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'sku_composition' => ['required', 'string', 'max:255', 'different:item_code'],
            'qty' => ['required', 'integer', 'min:1'],
        ];
    }

    protected function isEmptyRow(array $data): bool
    {
        return empty($data['item_code']) && empty($data['sku_composition']);
    }

    protected function process(array $data): void
    {
        $this->service->processBundleRow($data);
    }
}
