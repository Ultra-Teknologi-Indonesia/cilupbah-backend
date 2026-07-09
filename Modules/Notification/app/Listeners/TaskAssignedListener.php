<?php

namespace Modules\Notification\Listeners;

use App\Models\User;
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
        'stock_opname' => 'Stock Opname',
    ];

    public function handle(TaskAssigned $event): void
    {
        $label = self::TASK_LABELS[$event->taskType] ?? $event->taskType;
        $title = "{$label} baru di-assign ke kamu";
        $assignerName = $this->resolveAssignerName($event->assignedBy);
        $message = "{$label} {$event->taskNumber} telah di-assign oleh {$assignerName}.";

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
                ...$event->extra,
            ],
        );
    }

    private function resolveAssignerName(string $assignedBy): string
    {
        if (preg_match('/^[0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12}$/i', $assignedBy)) {
            $user = User::find($assignedBy);
            if ($user) {
                return $user->name ?? $user->email;
            }
        }

        if (str_contains($assignedBy, '@')) {
            $user = User::where('email', $assignedBy)->first();
            if ($user) {
                return $user->name ?? $user->email;
            }
        }

        return $assignedBy;
    }
}
