<?php

namespace Modules\Auth\Services;

use Modules\Auth\Repositories\RoleRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Support\Facades\DB;

class RoleService
{
    public function __construct(
        protected RoleRepository $roleRepository
    ) {}

    public function getPaginatedRoles(): LengthAwarePaginator
    {
        return $this->roleRepository->getPaginatedRoles();
    }

    public function createRole(array $data): Role
    {
        return DB::transaction(function () use ($data) {
            return $this->roleRepository->create([
                'name' => $data['name'],
                'guard_name' => 'web' // default spatie guard
            ]);
        });
    }

    public function updateRole(string $id, array $data): Role
    {
        return DB::transaction(function () use ($id, $data) {
            $role = $this->roleRepository->findById($id);

            if ($role->name === 'owner') {
                throw new HttpException(403, 'Role owner tidak dapat diubah.');
            }

            $this->roleRepository->update($role, [
                'name' => $data['name']
            ]);

            return $role;
        });
    }

    public function deleteRole(string $id): void
    {
        DB::transaction(function () use ($id) {
            $role = $this->roleRepository->findById($id);

            if ($role->name === 'owner') {
                throw new HttpException(403, 'Role owner tidak dapat dihapus.');
            }

            $this->roleRepository->delete($role);
        });
    }
}
