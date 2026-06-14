<?php

namespace Modules\Notification\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskAssigned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $assigneeUserId,
        public string $taskType,
        public string $taskNumber,
        public string $assignedBy,
        public array $extra = [],
    ) {}
}
