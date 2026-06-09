<?php

namespace Modules\Outbound\Repositories;

use Modules\Outbound\Models\Courier;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

class CourierRepository
{
    public function getAllPaginated(int $limit = 10)
    {
        return QueryBuilder::for(Courier::class)
            ->allowedFilters(
                AllowedFilter::exact('type'),
                AllowedFilter::exact('is_active'),
                AllowedFilter::partial('q', 'name'),
            )
            ->allowedSorts('name', 'code', 'created_at')
            ->defaultSort('name')
            ->paginate($limit);
    }

    public function getAll()
    {
        return Courier::where('is_active', true)->orderBy('name')->get();
    }

    public function findById(string $id): ?Courier
    {
        return Courier::find($id);
    }

    public function findByCode(string $code): ?Courier
    {
        return Courier::where('code', $code)->first();
    }

    public function create(array $data): Courier
    {
        return Courier::create($data);
    }

    public function update(string $id, array $data): bool
    {
        return Courier::where('id', $id)->update($data) > 0;
    }

    public function delete(string $id): bool
    {
        return Courier::where('id', $id)->delete() > 0;
    }
}
