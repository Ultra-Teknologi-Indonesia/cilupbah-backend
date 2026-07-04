<?php

namespace Modules\Outbound\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PicklistItemFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $picklistId,
        public string $itemId,
        public string $reasonCode,
        public int $failedQty,
        public string $userId,
    ) {}
}
