<?php

namespace Modules\Auth\Repositories;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class UserRepository
{
    public function getPaginatedUsers(): LengthAwarePaginator
    {
        return $this->baseQuery()
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    public function lookup(?string $q, int $page, int $perPage): array
    {
        $query = User::query()->with('roles');

        if (! empty($q)) {
            $query->where(function (Builder $w) use ($q) {
                $w->where('email', 'ilike', "%{$q}%")
                    ->orWhere('name', 'ilike', "%{$q}%");
            });
        }

        $total = (clone $query)->count();

        $users = $query->orderBy('email')
            ->forPage($page, $perPage)
            ->get();

        return [$users, $total];
    }

    public function getExportUsersQuery(): Builder
    {
        return $this->baseQuery()->getEloquentBuilder();
    }

    protected function baseQuery(): QueryBuilder
    {
        return QueryBuilder::for(User::class)
            ->with(['roles', 'permissions'])
            ->allowedSearch('name', 'email')
            ->allowedFilters(
                AllowedFilter::callback('role', function (Builder $query, $value) {
                    $query->whereHas('roles', fn (Builder $q) => $q->whereIn('name', (array) $value));
                }),
                AllowedFilter::exact('warehouse_id'),
            )
            ->allowedSorts('name', 'created_at')
            ->defaultSort('-created_at');
    }

    public function findById(string $id): User
    {
        return User::findOrFail($id);
    }

    public function findByIdWithRelations(string $id): User
    {
        return User::with(['roles', 'permissions'])->findOrFail($id);
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

    public function delete(User $user): bool
    {
        return $user->delete();
    }

    public function deleteTokens(User $user): void
    {
        $user->tokens()->delete();
    }

    public function deleteCurrentToken(User $user): void
    {
        $user->currentAccessToken()->delete();
    }
}
