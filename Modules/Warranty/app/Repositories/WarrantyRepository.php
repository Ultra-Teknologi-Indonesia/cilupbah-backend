<?php

namespace Modules\Warranty\Repositories;

use Modules\Warranty\Models\Warranty;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\QueryBuilder;

class WarrantyRepository
{
    public function getPaginated(int $limit = 10): LengthAwarePaginator
    {
        return QueryBuilder::for(Warranty::class)
            ->allowedSearch('serial_no')
            ->allowedFilters(['status', 'product_variant_id', 'order_id', 'serial_no'])
            ->allowedSorts(['created_at', 'start_date', 'end_date'])
            ->paginate($limit)
            ->appends(request()->query());
    }

    public function findById(string $id): ?Warranty
    {
        return Warranty::with(['productVariant', 'order'])->find($id);
    }

    public function create(array $data): Warranty
    {
        return Warranty::create($data);
    }

    public function update(string $id, array $data): bool
    {
        return Warranty::where('id', $id)->update($data) > 0;
    }

    public function delete(string $id): bool
    {
        return Warranty::where('id', $id)->delete() > 0;
    }
}
