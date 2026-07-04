<?php

namespace Modules\Sales\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderNeedsBuyerConfirmation
{
    use Dispatchable, SerializesModels;

    /**
     * @param string $orderId
     * @param string $picklistId
     * @param array<int, array{item_id:string, sku:?string, failed_qty:int, item_status:string}> $shortItems
     */
    public function __construct(
        public string $orderId,
        public string $picklistId,
        public array $shortItems,
    ) {}
}
