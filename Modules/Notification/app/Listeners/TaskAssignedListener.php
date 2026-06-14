<?php

namespace Modules\Notification\Listeners;

use Modules\Notification\Events\TaskAssigned;
use Modules\Notification\Models\Notification;
use Modules\Notification\Jobs\SendPushNotificationJob;

class TaskAssignedListener
{
    private const TASK_LABELS = [
        'picklist' => 'Picklist',
        'packlist' => 'Packlist',
        'putaway' => 'Putaway',
        'inbound' => 'Inbound',
    ];

    public function handle(TaskAssigned $event): void
    {
        $label = self::TASK_LABELS[$event->taskType] ?? $event->taskType;
        $title = "{$label} baru di-assign ke kamu";
        $message = "{$label} {$event->taskNumber} telah di-assign oleh {$event->assignedBy}.";

        $notification = Notification::create([
            'user_id' => $event->assigneeUserId,
            'title' => $title,
            'message' => $message,
            'type' => 'task_assigned',
            'data' => [
                'task_type' => $event->taskType,
                'task_number' => $event->taskNumber,
                'assigned_by' => $event->assignedBy,
                ...$event->extra,
            ],
        ]);

        SendPushNotificationJob::dispatch(
            $event->assigneeUserId,
            $title,
            $message,
            [
                'type' => 'task_assigned',
                'task_type' => $event->taskType,
                'notification_id' => $notification->id,
            ],
        );
    }
}
