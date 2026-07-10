<?php

namespace Modules\Auth\Repositories;

use App\Models\Permission;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

class PermissionRepository
{
    public function getAllPermissions(): Collection
    {
        return Permission::select('id', 'name')
            ->orderBy('name')
            ->get();
    }

    public function allNames(): SupportCollection
    {
        return Permission::pluck('name');
    }
}
