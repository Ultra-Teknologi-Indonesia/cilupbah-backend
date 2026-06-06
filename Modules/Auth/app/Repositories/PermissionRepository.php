<?php

namespace Modules\Auth\Repositories;

use App\Models\Permission;
use Spatie\QueryBuilder\QueryBuilder;
use Illuminate\Database\Eloquent\Collection;

class PermissionRepository
{
    public function getAllPermissions(): Collection
    {
        return QueryBuilder::for(Permission::class)
            ->select('id', 'name')
            ->allowedSorts('name')
            ->defaultSort('name')
            ->get();
    }
}
