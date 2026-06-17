<?php

namespace Modules\Auth\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Auth\Exports\UsersExport;
use Modules\Auth\Repositories\UserHistoryRepository;
use Modules\Auth\Repositories\UserRepository;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UserService
{
    public function __construct(
        protected UserRepository $userRepository,
        protected UserHistoryRepository $historyRepository
    ) {}

    public function getPaginatedUsers(): LengthAwarePaginator
    {
        return $this->userRepository->getPaginatedUsers();
    }

    public function getUserDetail(string $id): User
    {
        return $this->userRepository->findByIdWithRelations($id);
    }

    /**
     * Lookup user bergaya Jubelio untuk dropdown (mis. Default Staff gudang).
     * Mengembalikan { data: [{user_id, email, last_login, is_owner}], totalCount }.
     */
    public function getUserLookup(?string $q, int $page, int $pageSize): array
    {
        $query = User::query()->with('roles');

        if (! empty($q)) {
            $query->where(function ($w) use ($q) {
                $w->where('email', 'ilike', "%{$q}%")
                    ->orWhere('name', 'ilike', "%{$q}%");
            });
        }

        $total = (clone $query)->count();

        $users = $query->orderBy('email')
            ->forPage($page, $pageSize)
            ->get();

        $data = $users->map(fn (User $u) => [
            'user_id' => $u->id,
            'email' => $u->email,
            'last_login' => optional($u->last_login_at)->toIso8601String(),
            'is_owner' => $u->hasRole('owner'),
        ])->all();

        return ['data' => $data, 'totalCount' => $total];
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
        return DB::transaction(function () use ($data) {
            $user = $this->userRepository->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'nik' => $data['nik'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'avatar_media_id' => $data['avatar_media_id'] ?? null,
            ]);

            $user->assignRole($data['roles']);

            $this->historyRepository->createHistory([
                'actor_id' => Auth::id(),
                'target_user_id' => $user->id,
                'action' => 'created',
            ]);

            return $user;
        });
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

            $this->historyRepository->createHistory([
                'actor_id' => Auth::id(),
                'target_user_id' => $user->id,
                'action' => 'updated',
            ]);

            return $user;
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

    public function getUserHistories(string $userId): LengthAwarePaginator|Collection
    {
        $user = $this->userRepository->findById($userId);

        return $this->historyRepository->getHistoriesByUserId($user->id);
    }
}
