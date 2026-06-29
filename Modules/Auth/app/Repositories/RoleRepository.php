<?php

namespace Modules\Auth\Repositories;

use App\Models\Role;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\QueryBuilder;

class RoleRepository
{
    public function getPaginatedRoles(): LengthAwarePaginator
    {
        return QueryBuilder::for(Role::class)
            ->with('permissions:id,name')
            ->withCount('users')
            ->allowedSearch('name')
            ->allowedSorts('name', 'created_at')
            ->defaultSort('name')
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    public function findById(string $id): Role
    {
        return Role::findOrFail($id);
    }

    public function findByIdWithRelations(string $id): Role
    {
        return Role::with('permissions:id,name')->withCount('users')->findOrFail($id);
    }

    public function countUsers(Role $role): int
    {
        return $role->users()->count();
    }

    public function create(array $data): Role
    {
        return Role::create($data);
    }

    public function update(Role $role, array $data): bool
    {
        return $role->update($data);
    }

    public function delete(Role $role): bool
    {
        return $role->delete();
    }
}
