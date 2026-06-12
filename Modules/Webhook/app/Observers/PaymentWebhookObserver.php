<?php

namespace Modules\Webhook\Observers;

use Modules\Sales\Models\SalesPayment;
use Modules\Webhook\Support\WebhookEvent;

class PaymentWebhookObserver extends AbstractWebhookObserver
{
    public function created(SalesPayment $payment): void
    {
        $this->emit(WebhookEvent::PAYMENT, [
            'id' => $payment->id,
            'payment_number' => $payment->payment_number,
            'sales_invoice_id' => $payment->sales_invoice_id,
            'amount' => $payment->amount,
        ]);
    }
}
