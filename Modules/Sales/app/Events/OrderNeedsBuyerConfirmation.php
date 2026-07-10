<?php

namespace Modules\Sales\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderNeedsBuyerConfirmation
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $orderId,
        public string $picklistId,
        public array $shortItems,
    ) {}
}
