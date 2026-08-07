<?php

namespace Modules\Notification\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;

class NotificationRecipientResolver
{
    private const OWNER_ROLE = 'owner';

    public function byPermission(string $permission, ?string $locationId = null): array
    {
        $ids = [];
        try {
            $ids = User::permission($permission)->pluck('id')->all();
        } catch (\Throwable $e) {
            Log::warning('NotificationRecipientResolver: permission tidak dikenal', [
                'permission' => $permission,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->mergeWithOwners($this->filterByLocation($ids, $locationId));
    }

    public function byRole(string $role, ?string $locationId = null): array
    {
        $ids = [];
        try {
            $ids = User::role($role)->pluck('id')->all();
        } catch (\Throwable $e) {
            Log::warning('NotificationRecipientResolver: role tidak dikenal', [
                'role' => $role,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->mergeWithOwners($this->filterByLocation($ids, $locationId));
    }

    private function filterByLocation(array $ids, ?string $locationId): array
    {
        if ($locationId === null || $ids === []) {
            return $ids;
        }

        $excluded = User::query()
            ->whereIn('id', $ids)
            ->whereHas('locations')
            ->whereDoesntHave('locations', fn ($q) => $q->where('locations.id', $locationId))
            ->pluck('id')
            ->all();

        return array_values(array_diff($ids, $excluded));
    }

    private function mergeWithOwners(array $ids): array
    {
        try {
            $owners = User::role(self::OWNER_ROLE)->pluck('id')->all();
            $ids = array_merge($ids, $owners);
        } catch (\Throwable $e) {
            Log::warning('NotificationRecipientResolver: gagal resolusi penerima owner', [
                'role' => self::OWNER_ROLE,
                'error' => $e->getMessage(),
            ]);
        }

        return array_values(array_unique(array_filter($ids)));
    }
}
