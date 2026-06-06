<?php

namespace Modules\Auth\Repositories;

use App\Models\Role;
use Spatie\QueryBuilder\QueryBuilder;
use Illuminate\Database\Eloquent\Collection;

class RoleRepository
{
    public function getAllRoles(): Collection
    {
        return Role::select('id', 'name')->get();
    }
}
