<?php

namespace Modules\Notification\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;

class NotificationRecipientResolver
{
    private const OWNER_ROLE = 'owner';

    public function byPermission(string $permission): array
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

        return $this->mergeWithOwners($ids);
    }

    public function byRole(string $role): array
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

        return $this->mergeWithOwners($ids);
    }

    private function mergeWithOwners(array $ids): array
    {
        try {
            $owners = User::role(self::OWNER_ROLE)->pluck('id')->all();
            $ids = array_merge($ids, $owners);
        } catch (\Throwable $e) {
        }

        return array_values(array_unique(array_filter($ids)));
    }
}
