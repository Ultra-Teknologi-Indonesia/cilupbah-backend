<?php

namespace Modules\Auth\Services;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Repositories\UserRepository;
use Modules\Auth\Repositories\UserHistoryRepository;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class UserService
{
    public function __construct(
        protected UserRepository $userRepository,
        protected UserHistoryRepository $historyRepository
    ) {}

    /**
     * Get paginated users.
     */
    public function getPaginatedUsers(): LengthAwarePaginator
    {
        return $this->userRepository->getPaginatedUsers();
    }

    /**
     * Get query for user export.
     */
    public function getExportUsersQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return $this->userRepository->getExportUsersQuery();
    }

    /**
     * Create a new user with assigned role.
     */
    public function createUser(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = $this->userRepository->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'nik' => $data['nik'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
            ]);

            $user->assignRole($data['roles']);

            return $user;
        });
    }

    /**
     * Update an existing user.
     */
    public function updateUser(string $id, array $data): User
    {
        return DB::transaction(function () use ($id, $data) {
            $user = $this->userRepository->findById($id);

            $updateData = [
                'name' => $data['name'],
                'email' => $data['email'],
                'nik' => $data['nik'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
            ];

            if (isset($data['password']) && !empty($data['password'])) {
                $updateData['password'] = Hash::make($data['password']);
            }

            $this->userRepository->update($user, $updateData);

            $user->syncRoles($data['roles']);

            return $user;
        });
    }

    /**
     * Force logout a specific user.
     */
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

    /**
     * Bulk force logout multiple users.
     */
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

    /**
     * Get paginated user histories.
     */
    public function getUserHistories(string $userId): LengthAwarePaginator|Collection
    {
        $user = $this->userRepository->findById($userId);
        return $this->historyRepository->getHistoriesByUserId($user->id);
    }
}
