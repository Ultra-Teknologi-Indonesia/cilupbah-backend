<?php

declare(strict_types=1);

namespace Modules\Notification\Services;

use IlluminateContracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Modules\Notification\Models\DeviceToken;
use Modules\Notification\Models\Notification;
use Modules\Notification\Repositories\NotificationRepository;

final class NotificationService
{
    public function __construct(
        private readonly NotificationRepository $repository,
    ) {}

    public function paginateForUser(string $userId, Request $request): LengthAwarePaginator
    {
        return $this->repository->paginatedForUser($userId, $request);
    }

    public function unreadCount(string $userId): int
    {
        return $this->repository->countUnreadForUser($userId);
    }

    public function findForUser(string $userId, string $notificationId): Notification
    {
        return $this->repository->findForUser($userId, $notificationId);
    }

    public function markAsRead(string $userId, string $notificationId): Notification
    {
        $notification = $this->findForUser($userId, $notificationId);

        return $this->repository->markAsRead($notification);
    }

    public function markAllAsRead(string $userId): void
    {
        $this->repository->markAllReadForUser($userId);
    }

    public function deleteForUser(string $userId, string $notificationId): void
    {
        $notification = $this->findForUser($userId, $notificationId);
        $this->repository->deleteNotification($notification);
    }

    public function taskCounts(): array
    {
        return $this->repository->taskCounts();
    }

    public function registerDeviceToken(
        string $userId,
        string $fcmToken,
        ?string $deviceId,
        ?string $platform,
    ): DeviceToken {
        return $this->repository->updateOrCreateToken($userId, $fcmToken, $deviceId, $platform);
    }

    public function removeDeviceToken(string $userId, string $fcmToken): void
    {
        $this->repository->deleteTokenForUser($userId, $fcmToken);
    }

    public function removeToken(string $fcmToken): void
    {
        $this->repository->deleteToken($fcmToken);
    }
}
