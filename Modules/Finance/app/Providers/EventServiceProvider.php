<?php

namespace Modules\Finance\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{

    protected $listen = [
        \Modules\Sales\Events\SalesInvoiceFinalized::class => [
            \Modules\Finance\Listeners\SynchronizeSalesInvoiceJournal::class,
        ],
    ];

    protected static $shouldDiscoverEvents = true;

    public function boot(): void
    {
        parent::boot();

        \Modules\Sales\Models\SalesInvoice::observe(\Modules\Finance\Observers\SalesInvoiceJournalObserver::class);
        \Modules\Sales\Models\SalesPayment::observe(\Modules\Finance\Observers\SalesPaymentJournalObserver::class);
        \Modules\Purchase\Models\PurchaseBill::observe(\Modules\Finance\Observers\PurchaseBillJournalObserver::class);
        \Modules\Purchase\Models\PurchasePayment::observe(\Modules\Finance\Observers\PurchasePaymentJournalObserver::class);
        \Modules\Sales\Models\SalesReturnSettlementRefund::observe(\Modules\Finance\Observers\SalesReturnRefundJournalObserver::class);
    }

    protected function configureEmailVerification(): void {}
}
