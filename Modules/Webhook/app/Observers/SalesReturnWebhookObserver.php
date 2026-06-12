<?php

namespace Modules\Webhook\Observers;

use Modules\Sales\Models\SalesReturn;
use Modules\Webhook\Support\WebhookEvent;

class SalesReturnWebhookObserver extends AbstractWebhookObserver
{
    public function created(SalesReturn $return): void
    {
        $this->emit(WebhookEvent::SALES_RETURN, [
            'id' => $return->id,
            'return_number' => $return->return_number,
            'customer_name' => $return->customer_name,
            'order_id' => $return->order_id,
            'status' => $return->status,
        ]);
    }
}
