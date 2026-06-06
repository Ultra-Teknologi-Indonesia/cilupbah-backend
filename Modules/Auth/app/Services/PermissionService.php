<?php

namespace Modules\Auth\Services;

use Modules\Auth\Repositories\PermissionRepository;
use Illuminate\Database\Eloquent\Collection;

class PermissionService
{
    public function __construct(
        protected PermissionRepository $permissionRepository
    ) {}

    public function getAllPermissions(): Collection
    {
        return $this->permissionRepository->getAllPermissions();
    }
}
