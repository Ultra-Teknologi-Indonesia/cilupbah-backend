<?php

declare(strict_types=1);

namespace Modules\Finance\Listeners;

use Modules\Finance\Observers\SalesInvoiceJournalObserver;
use Modules\Sales\Events\SalesInvoiceFinalized;

final class SynchronizeSalesInvoiceJournal
{
    public function __construct(
        private readonly SalesInvoiceJournalObserver $observer,
    ) {}

    public function handle(SalesInvoiceFinalized $event): void
    {
        $this->observer->synchronize($event->invoice);
    }
}
