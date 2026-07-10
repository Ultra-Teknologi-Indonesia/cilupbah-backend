<?php

namespace Modules\Inventory\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Inventory\Models\ImpexActivity;
use Spatie\QueryBuilder\QueryBuilder;

class ImpexActivityRepository
{
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $query = QueryBuilder::for(ImpexActivity::class)
            ->with('user')
            ->where('direction', $filters['direction'])
            ->defaultSort('-created_at')
            ->allowedSorts('created_at', 'started_at', 'completed_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['my_only']) && ! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        return $query->paginate(request('per_page', $perPage))
            ->appends(request()->query());
    }

    public function findOrFail(string $activityId): ImpexActivity
    {
        return ImpexActivity::findOrFail($activityId);
    }

    public function detailsFor(string $activityId): Collection
    {
        return $this->findOrFail($activityId)->details()->orderBy('created_at')->get();
    }
}
