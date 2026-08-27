<?php

declare(strict_types=1);

namespace Modules\Report\Http\Requests;

use App\Support\WarehouseAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Warehouse\Models\Location;

final class InventoryStockExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['item_ids', 'location_ids'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $this->merge([$field => array_values(array_filter(array_map('trim', explode(',', $value))))]);
            }
        }

        foreach (['only_not_restocked', 'only_with_stock'] as $field) {
            if ($this->has($field)) {
                $this->merge([$field => filter_var($this->input($field), FILTER_VALIDATE_BOOLEAN)]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'report_type' => ['required', 'string', 'in:by_location,as_of_date,by_rack'],
            'item_ids' => ['nullable', 'array', 'max:5000'],
            'item_ids.*' => ['uuid', 'distinct'],
            'location_ids' => ['nullable', 'array', 'max:100'],
            'location_ids.*' => ['uuid', 'distinct'],
            'location_id' => ['required_if:report_type,by_rack', 'nullable', 'uuid'],
            'as_of_date' => ['required_if:report_type,as_of_date', 'nullable', 'date', 'before_or_equal:today'],
            'stock_filter' => ['sometimes', 'string', 'in:all,positive,zero'],
            'only_not_restocked' => ['sometimes', 'boolean'],
            'only_with_stock' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('report_type') !== 'by_rack' || ! $this->filled('location_id')) {
                return;
            }

            $location = Location::query()->find($this->input('location_id'));

            if (! $location || ! $location->is_active || ! $location->is_warehouse) {
                $validator->errors()->add('location_id', 'Lokasi rak harus berupa gudang aktif.');

                return;
            }

            if ($location->location_code === Location::SYSTEM_TRANSIT_CODE) {
                $validator->errors()->add('location_id', 'Lokasi Transit tidak dapat digunakan untuk laporan persediaan per rak.');
            }

            $allowedIds = WarehouseAccess::allowedIds();
            if ($allowedIds !== null && ! in_array($location->getKey(), $allowedIds, true)) {
                $validator->errors()->add('location_id', 'Anda tidak memiliki akses ke gudang tersebut.');
            }
        });
    }

    public function normalized(): array
    {
        $data = $this->validated();

        return [
            'report_type' => $data['report_type'],
            'item_ids' => array_values($data['item_ids'] ?? []),
            'location_ids' => array_values($data['location_ids'] ?? []),
            'location_id' => $data['location_id'] ?? null,
            'as_of_date' => $data['as_of_date'] ?? null,
            'stock_filter' => $data['stock_filter'] ?? 'all',
            'only_not_restocked' => (bool) ($data['only_not_restocked'] ?? false),
            'only_with_stock' => (bool) ($data['only_with_stock'] ?? false),
        ];
    }
}
