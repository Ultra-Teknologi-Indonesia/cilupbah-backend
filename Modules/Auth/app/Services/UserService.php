<?php

namespace Modules\Auth\Services;

use App\Models\User;
use Modules\Auth\Support\PermissionCatalog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
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

    public function getUserLookup(?string $q, int $page = 1, int $perPage = 50, ?string $role = null): array
    {
        [$users, $total] = $this->userRepository->lookup($q, $page, $perPage, $role);

        return [
            'data' => $users,
            'totalCount' => $total,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) ceil($total / max(1, $perPage)),
            ],
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
                $user->syncPermissions(
                    PermissionCatalog::withViewPrerequisites($data['permissions'] ?? []),
                );
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
                $user->syncPermissions(
                    PermissionCatalog::withViewPrerequisites($data['permissions'] ?? []),
                );
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

            $user->syncPermissions(
                PermissionCatalog::withViewPrerequisites($permissionNames),
            );

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
        try {
            DB::transaction(function () use ($id) {
                $user = $this->userRepository->findById($id);

                if ($user->id === Auth::id()) {
                    throw new HttpException(422, 'Anda tidak dapat menghapus akun sendiri.');
                }

                if ($user->hasRole('owner')) {
                    throw new HttpException(403, 'Akun owner tidak dapat dihapus.');
                }

                DB::table('inbounds')
                    ->where('assigned_to', $user->id)
                    ->whereIn('status', ['DRAFT', 'PARTIAL'])
                    ->update(['assigned_to' => null]);
                DB::table('stock_replenishment_requests')
                    ->where('assignee_user_id', $user->id)
                    ->whereIn('status', ['PENDING', 'ACCEPTED'])
                    ->update(['assignee_user_id' => null]);
                DB::table('inbound_participants')
                    ->where('user_id', $user->id)
                    ->where('status', 'ACTIVE')
                    ->update([
                        'status' => 'WITHDRAWN',
                        'withdrawn_by' => Auth::id(),
                        'withdraw_reason' => 'Akun pengguna dihapus',
                        'withdrawn_at' => now(),
                        'updated_at' => now(),
                    ]);

                $user->syncRoles([]);
                $user->syncPermissions([]);
                $user->locations()->detach();
                $this->userRepository->deleteTokens($user);

                $this->historyRepository->createHistory([
                    'actor_id' => Auth::id(),
                    'target_user_id' => $user->id,
                    'action' => 'deleted',
                ]);

                $this->userRepository->delete($user);

                Log::info('Pengguna dihapus', [
                    'user_id' => $user->id,
                    'actor_id' => Auth::id(),
                ]);
            });
        } catch (QueryException $exception) {

            if ($this->isForeignKeyViolation($exception)) {
                throw new HttpException(
                    409,
                    'Pengguna masih terhubung ke data yang tidak dapat dilepas. Lepaskan hubungan tersebut lalu coba lagi.',
                );
            }

            throw $exception;
        }
    }

    private function isForeignKeyViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (string) ($exception->errorInfo[1] ?? '');

        if ($sqlState === '23503' || in_array($driverCode, ['1217', '1451'], true)) {
            return true;
        }

        return $sqlState === '23000'
            && str_contains(strtolower($exception->getMessage()), 'foreign key');
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
        return $this->historyRepository->getLoginHistoriesByUserId($userId);
    }

    public function getUserHistories(string $userId): LengthAwarePaginator|Collection
    {
        return $this->historyRepository->getHistoriesByUserId($userId);
    }
}
