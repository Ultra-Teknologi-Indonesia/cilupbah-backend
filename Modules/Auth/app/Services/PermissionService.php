<?php

namespace Modules\Auth\Services;

use Modules\Auth\Repositories\PermissionRepository;
use Modules\Auth\Support\PermissionCatalog;
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

    public function getCatalog(): array
    {
        return PermissionCatalog::matrix();
    }
}
