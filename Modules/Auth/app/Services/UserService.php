<?php

namespace Modules\Auth\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Auth\Exports\UsersExport;
use Modules\Auth\Http\Resources\UserLookupResource;
use Modules\Auth\Repositories\PermissionRepository;
use Modules\Auth\Repositories\UserHistoryRepository;
use Modules\Auth\Repositories\UserLocationRepository;
use Modules\Auth\Repositories\UserRepository;
use Modules\Notification\Services\NotificationDispatcher;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UserService
{
    protected ?SupportCollection $allPermissionNames = null;

    public function __construct(
        protected UserRepository $userRepository,
        protected UserHistoryRepository $historyRepository,
        protected UserLocationRepository $userLocationRepository,
        protected PermissionRepository $permissionRepository,
        protected NotificationDispatcher $notifications,
    ) {}

    public function attachProfileContext(User $user): User
    {
        // location_tree & all_permission_names adalah data transien untuk ProfileResource,
        // BUKAN kolom tabel users. Dipasang lewat setProfileContext (disimpan di luar
        // attribute bag & relations) supaya tidak ikut di-persist saat instance yang sama
        // di-save()/update() (mis. setAvatar) — yang sebelumnya memicu error 500
        // "column location_tree does not exist".
        $user->setProfileContext('location_tree', $this->userLocationRepository->getLocationTree($user->id));

        if ($user->hasRole('owner')) {
            $user->setProfileContext(
                'all_permission_names',
                $this->allPermissionNames ??= $this->permissionRepository->allNames()
            );
        }

        return $user;
    }

    public function getPaginatedUsers(): LengthAwarePaginator
    {
        return $this->userRepository->getPaginatedUsers();
    }

    public function getUserDetail(string $id): User
    {
        return $this->userRepository->findByIdWithRelations($id);
    }

    public function getUserLookup(?string $q, int $page, int $perPage): array
    {
        [$users, $total] = $this->userRepository->lookup($q, $page, $perPage);

        return [
            'data' => UserLookupResource::collection($users),
            'totalCount' => $total,
        ];
    }

    public function downloadUsersExport(): BinaryFileResponse
    {
        return Excel::download(
            new UsersExport($this->userRepository->getExportUsersQuery()),
            'users_export_' . now()->format('Ymd_His') . '.xlsx'
        );
    }

    public function createUser(array $data): User
    {
        $user = DB::transaction(function () use ($data) {
            $user = $this->userRepository->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'nik' => $data['nik'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'avatar_media_id' => $data['avatar_media_id'] ?? null,
            ]);

            $user->assignRole($data['roles']);

            if (array_key_exists('permissions', $data)) {
                $user->syncPermissions($data['permissions'] ?? []);
            }

            if (array_key_exists('location_ids', $data)) {
                $user->syncLocations($data['location_ids'] ?? []);
            }

            $this->historyRepository->createHistory([
                'actor_id' => Auth::id(),
                'target_user_id' => $user->id,
                'action' => 'created',
            ]);

            return $user;
        });

        $this->notifications->toPermission('view-user', [
            'type' => 'user_created',
            'title' => 'Pengguna baru terdaftar',
            'message' => "{$user->name} ({$user->email}) ditambahkan sebagai pengguna.",
            'data' => [
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'link' => "/dashboard/pengaturan/pengguna/{$user->id}",
            ],
        ], excludeUserIds: array_filter([Auth::id() ?: null]));

        return $user;
    }

    public function updateUser(string $id, array $data): User
    {
        return DB::transaction(function () use ($id, $data) {
            $user = $this->userRepository->findById($id);

            $updateData = [
                'name' => $data['name'],
                'email' => $data['email'],
            ];

            foreach (['nik', 'warehouse_id', 'avatar_media_id'] as $optionalField) {
                if (array_key_exists($optionalField, $data)) {
                    $updateData[$optionalField] = $data[$optionalField];
                }
            }

            if (! empty($data['password'])) {
                $updateData['password'] = Hash::make($data['password']);
            }

            $this->userRepository->update($user, $updateData);

            $user->syncRoles($data['roles']);

            if (array_key_exists('permissions', $data)) {
                $user->syncPermissions($data['permissions'] ?? []);
            }

            if (array_key_exists('location_ids', $data)) {
                $user->syncLocations($data['location_ids'] ?? []);
            }

            $this->historyRepository->createHistory([
                'actor_id' => Auth::id(),
                'target_user_id' => $user->id,
                'action' => 'updated',
            ]);

            return $user;
        });
    }

    public function syncPermissions(string $id, array $permissionNames): User
    {
        return DB::transaction(function () use ($id, $permissionNames) {
            $user = $this->userRepository->findById($id);

            if ($user->hasRole('owner')) {
                throw new HttpException(403, 'Hak akses owner tidak dapat diubah (owner punya akses penuh).');
            }

            $user->syncPermissions($permissionNames);

            $this->historyRepository->createHistory([
                'actor_id' => Auth::id(),
                'target_user_id' => $user->id,
                'action' => 'updated',
            ]);

            return $user->load('permissions');
        });
    }

    public function deleteUser(string $id): void
    {
        DB::transaction(function () use ($id) {
            $user = $this->userRepository->findById($id);

            if ($user->id === Auth::id()) {
                throw new HttpException(422, 'Anda tidak dapat menghapus akun sendiri.');
            }

            if ($user->hasRole('owner')) {
                throw new HttpException(403, 'Akun owner tidak dapat dihapus.');
            }

            $this->userRepository->deleteTokens($user);
            $this->userRepository->delete($user);

            Log::info('Pengguna dihapus', [
                'user_id' => $user->id,
                'email' => $user->email,
                'actor_id' => Auth::id(),
            ]);
        });
    }

    public function setAvatar(User $user, ?string $mediaUuid): User
    {
        $this->userRepository->update($user, ['avatar_media_id' => $mediaUuid]);

        return $user->refresh();
    }

    public function forceLogout(string $id): void
    {
        $user = $this->userRepository->findById($id);

        $this->userRepository->deleteTokens($user);

        $this->historyRepository->createHistory([
            'actor_id' => Auth::id(),
            'target_user_id' => $user->id,
            'action' => 'force_logged_out',
        ]);
    }

    public function bulkForceLogout(array $userIds): void
    {
        $actorId = Auth::id();
        $users = $this->userRepository->findByIds($userIds);

        foreach ($users as $user) {
            $this->userRepository->deleteTokens($user);

            $this->historyRepository->createHistory([
                'actor_id' => $actorId,
                'target_user_id' => $user->id,
                'action' => 'force_logged_out',
            ]);
        }
    }

    public function getLoginHistories(string $userId): LengthAwarePaginator
    {
        $user = $this->userRepository->findById($userId);

        return $this->historyRepository->getLoginHistoriesByUserId($user->id);
    }

    public function getUserHistories(string $userId): LengthAwarePaginator|Collection
    {
        $user = $this->userRepository->findById($userId);

        return $this->historyRepository->getHistoriesByUserId($user->id);
    }
}
