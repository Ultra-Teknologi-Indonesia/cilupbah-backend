<?php

namespace Modules\Auth\Repositories;

use App\Models\Role;
use Spatie\QueryBuilder\QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;

class RoleRepository
{
    public function getPaginatedRoles(): LengthAwarePaginator
    {
        return QueryBuilder::for(Role::class)
            ->with('permissions:id,name')
            ->allowedSearch('name')
            ->allowedSorts('name', 'created_at')
            ->defaultSort('name')
            ->paginate(request('per_page', 10))
            ->appends(request()->query());
    }

    public function findById(string $id): Role
    {
        return Role::findOrFail($id);
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
