<?php

declare(strict_types=1);

namespace Modules\Sales\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Sales\Models\SalesInvoice;

final class SalesInvoiceFinalized
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public SalesInvoice $invoice,
    ) {}
}
