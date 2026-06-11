<?php

namespace Modules\Webhook\Observers;

use Modules\Sales\Models\SalesInvoice;
use Modules\Webhook\Support\WebhookEvent;

class InvoiceWebhookObserver extends AbstractWebhookObserver
{
    public function created(SalesInvoice $invoice): void
    {
        $this->emit(WebhookEvent::INVOICE, [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'customer_name' => $invoice->customer_name,
            'order_id' => $invoice->order_id,
            'paid_amount' => $invoice->paid_amount,
        ]);
    }
}
