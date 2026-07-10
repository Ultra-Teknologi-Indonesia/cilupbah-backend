<?php

namespace Modules\Sales\Repositories;

use Modules\Sales\Models\InternalStore;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class InternalStoreRepository
{
    public function getAllPaginated(int $perPage = 10)
    {
        return QueryBuilder::for(InternalStore::class)
            ->allowedFilters(
                AllowedFilter::exact('is_active'),
            )
            ->allowedSearch('name', 'code')
            ->allowedSorts('name', 'code', 'created_at')
            ->defaultSort('-created_at')
            ->paginate($perPage)
            ->appends(request()->query());
    }

    public function getAllActive()
    {
        return InternalStore::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function findById(string $id): ?InternalStore
    {
        return InternalStore::find($id);
    }

    public function nextCode(): string
    {
        $last = InternalStore::withTrashed()
            ->orderByDesc('code')
            ->lockForUpdate()
            ->first();

        $seq = 1;
        if ($last && preg_match('/^TKI-(\d+)$/', $last->code, $m)) {
            $seq = ((int) $m[1]) + 1;
        }

        return 'TKI-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
