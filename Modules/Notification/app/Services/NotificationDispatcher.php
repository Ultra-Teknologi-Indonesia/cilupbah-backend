<?php

namespace Modules\Notification\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Notification\Jobs\SendPushNotificationJob;
use Modules\Notification\Models\Notification;

class NotificationDispatcher
{
    public function __construct(
        private NotificationRecipientResolver $resolver,
    ) {}

    public function toUser(string $userId, array $payload): void
    {
        $this->toUsers([$userId], $payload);
    }

    public function toUsers(array $userIds, array $payload): void
    {
        $userIds = array_values(array_unique(array_filter($userIds)));
        if (empty($userIds)) {
            return;
        }

        $type = $payload['type'] ?? 'general';
        $title = (string) ($payload['title'] ?? '');
        $message = (string) ($payload['message'] ?? '');
        $data = (array) ($payload['data'] ?? []);
        $push = $payload['push'] ?? true;

        DB::afterCommit(function () use ($userIds, $type, $title, $message, $data, $push) {
            foreach ($userIds as $uid) {
                try {
                    $notification = Notification::create([
                        'user_id' => $uid,
                        'title' => $title,
                        'message' => $message,
                        'type' => $type,
                        'data' => $data,
                    ]);

                    if ($push) {
                        SendPushNotificationJob::dispatch(
                            $uid,
                            $title,
                            $message,
                            array_merge($data, [
                                'type' => $type,
                                'notification_id' => $notification->id,
                                'target_user_id' => $uid,
                            ]),
                        );
                    }
                } catch (\Throwable $e) {
                    Log::warning('NotificationDispatcher: gagal kirim ke user', [
                        'user_id' => $uid,
                        'type' => $type,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }

    public function toPermission(string $permission, array $payload, array $excludeUserIds = [], ?string $locationId = null): void
    {
        $userIds = array_diff($this->resolver->byPermission($permission, $locationId), $excludeUserIds);
        $this->toUsers($userIds, $payload);
    }

    public function toRole(string $role, array $payload, array $excludeUserIds = [], ?string $locationId = null): void
    {
        $userIds = array_diff($this->resolver->byRole($role, $locationId), $excludeUserIds);
        $this->toUsers($userIds, $payload);
    }
}
