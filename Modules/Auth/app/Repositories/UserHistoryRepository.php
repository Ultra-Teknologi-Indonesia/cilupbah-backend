<?php

namespace Modules\Auth\Repositories;

use App\Models\LoginHistory;
use App\Models\User;
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
            ->where(function ($query) use ($userId) {
                $query->where('target_user_id', $userId)
                    ->orWhere('target_user_id_snapshot', $userId);
            })
            ->allowedSorts('created_at', 'action')
            ->defaultSort('-created_at')
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    public function getLoginHistoriesByUserId(string $userId): LengthAwarePaginator
    {
        return LoginHistory::where(function ($query) use ($userId) {
                $query->where('user_id', $userId)
                    ->orWhere('user_id_snapshot', $userId);
            })
            ->with('user:id,name,email')
            ->orderByDesc('created_at')
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    public function createHistory(array $data): UserHistory
    {
        if (! empty($data['actor_id'])) {
            $actor = User::query()->find($data['actor_id'], ['id', 'name', 'email']);
            $data['actor_id_snapshot'] ??= $actor?->id;
            $data['actor_user_name'] ??= $actor?->name;
            $data['actor_user_email'] ??= $actor?->email;
        }

        if (! empty($data['target_user_id'])) {
            $target = User::query()->find($data['target_user_id'], ['id', 'name', 'email']);
            $data['target_user_id_snapshot'] ??= $target?->id;
            $data['target_user_name'] ??= $target?->name;
            $data['target_user_email'] ??= $target?->email;
        }

        return UserHistory::create($data);
    }
}
