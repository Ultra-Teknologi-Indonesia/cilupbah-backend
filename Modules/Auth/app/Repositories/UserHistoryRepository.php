<?php

namespace Modules\Auth\Repositories;

use App\Models\LoginHistory;
use App\Models\UserHistory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\QueryBuilder;

class UserHistoryRepository
{
    public function getHistoriesByUserId(string $userId): LengthAwarePaginator|Collection
    {
        return QueryBuilder::for(UserHistory::class)
            ->with(['actor', 'targetUser'])
            ->where('target_user_id', $userId)
            ->allowedSorts('created_at', 'action')
            ->defaultSort('-created_at')
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    public function getLoginHistoriesByUserId(string $userId): LengthAwarePaginator
    {
        return LoginHistory::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    public function createHistory(array $data): UserHistory
    {
        return UserHistory::create($data);
    }
}
