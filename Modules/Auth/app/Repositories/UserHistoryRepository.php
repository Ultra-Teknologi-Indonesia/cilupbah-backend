<?php

namespace Modules\Auth\Repositories;

use App\Models\UserHistory;
use Spatie\QueryBuilder\QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class UserHistoryRepository
{
    public function getHistoriesByUserId(string $userId): LengthAwarePaginator|Collection
    {
        return QueryBuilder::for(UserHistory::class)
            ->with(['actor', 'targetUser'])
            ->where('target_user_id', $userId)
            ->allowedSorts('created_at', 'action')
            ->defaultSort('-created_at')
            ->paginate(request('per_page', 10))
            ->appends(request()->query());
    }

    public function createHistory(array $data): UserHistory
    {
        return UserHistory::create($data);
    }
}
