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

    /**
     * Struktur matriks Hak Akses bergrup untuk render FE.
     *
     * @return list<array<string,mixed>>
     */
    public function getCatalog(): array
    {
        return PermissionCatalog::matrix();
    }
}
