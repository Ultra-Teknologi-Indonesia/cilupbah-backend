<?php

namespace Modules\Auth\Repositories;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class UserRepository
{
    public function getPaginatedUsers(): \Illuminate\Pagination\LengthAwarePaginator
    {
        return \Spatie\QueryBuilder\QueryBuilder::for(User::class)
            ->with(['roles', 'permissions']) // Eager load roles & permissions
            ->allowedSearch('name', 'email')
            ->allowedFilters([
                \Spatie\QueryBuilder\AllowedFilter::scope('role'),
                \Spatie\QueryBuilder\AllowedFilter::exact('warehouse_id')
            ])
            ->allowedSorts('name', 'created_at')
            ->defaultSort('-created_at')
            ->paginate(request('per_page', 10))
            ->appends(request()->query());
    }

    public function getExportUsersQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return \Spatie\QueryBuilder\QueryBuilder::for(User::class)
            ->with(['roles', 'permissions']) // Eager load roles & permissions
            ->allowedSearch('name', 'email')
            ->allowedFilters([
                \Spatie\QueryBuilder\AllowedFilter::scope('role'),
                \Spatie\QueryBuilder\AllowedFilter::exact('warehouse_id')
            ])
            ->allowedSorts('name', 'created_at')
            ->defaultSort('-created_at');
    }
    public function findById(string $id): User
    {
        return User::findOrFail($id);
    }

    public function findByIds(array $ids): Collection
    {
        return User::whereIn('id', $ids)->get();
    }

    public function findByEmail(string $email): ?User
    {
        return User::where('email', $email)->first();
    }

    public function create(array $data): User
    {
        return User::create($data);
    }

    public function update(User $user, array $data): bool
    {
        return $user->update($data);
    }

    public function deleteTokens(User $user): void
    {
        $user->tokens()->delete();
    }
}
