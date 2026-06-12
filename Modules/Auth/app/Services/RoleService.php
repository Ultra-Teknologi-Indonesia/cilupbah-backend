<?php

namespace Modules\Auth\Services;

use App\Models\Role;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Auth\Repositories\RoleRepository;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RoleService
{
    public function __construct(
        protected RoleRepository $roleRepository
    ) {}

    public function getPaginatedRoles(): LengthAwarePaginator
    {
        return $this->roleRepository->getPaginatedRoles();
    }

    public function getRoleDetail(string $id): Role
    {
        return $this->roleRepository->findByIdWithRelations($id);
    }

    public function createRole(array $data): Role
    {
        return DB::transaction(function () use ($data) {
            $role = $this->roleRepository->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'guard_name' => 'web',
            ]);

            Log::info('Role dibuat', ['role' => $role->name, 'actor_id' => Auth::id()]);

            return $role;
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
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
            ]);

            Log::info('Role diubah', ['role' => $role->name, 'actor_id' => Auth::id()]);

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

            $usersCount = $this->roleRepository->countUsers($role);
            if ($usersCount > 0) {
                throw new HttpException(422, "Role tidak dapat dihapus karena masih dipakai {$usersCount} pengguna. Pindahkan pengguna ke role lain terlebih dahulu.");
            }

            $this->roleRepository->delete($role);

            Log::info('Role dihapus', ['role' => $role->name, 'actor_id' => Auth::id()]);
        });
    }

    public function syncPermissions(string $id, array $permissionNames): Role
    {
        return DB::transaction(function () use ($id, $permissionNames) {
            $role = $this->roleRepository->findById($id);

            if ($role->name === 'owner') {
                throw new HttpException(403, 'Permission role owner tidak dapat diubah (owner punya akses penuh).');
            }

            $role->syncPermissions($permissionNames);

            Log::info('Permission role disinkronkan', [
                'role' => $role->name,
                'permissions' => $permissionNames,
                'actor_id' => Auth::id(),
            ]);

            return $role->load('permissions');
        });
    }
}
