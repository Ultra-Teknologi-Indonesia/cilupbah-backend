<?php

namespace Modules\Finance\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [];

    /**
     * Indicates if events should be discovered.
     *
     * @var bool
     */
    protected static $shouldDiscoverEvents = true;

    /**
     * Daftarkan observer jurnal otomatis (non-invasif, pola modul Webhook).
     * Observer hanya MEMBACA dokumen sumber + menulis tabel journals milik Finance —
     * tanpa mengubah modul Sales/Purchase.
     */
    public function boot(): void
    {
        parent::boot();

        \Modules\Sales\Models\SalesInvoice::observe(\Modules\Finance\Observers\SalesInvoiceJournalObserver::class);
        \Modules\Sales\Models\SalesPayment::observe(\Modules\Finance\Observers\SalesPaymentJournalObserver::class);
        \Modules\Purchase\Models\PurchaseBill::observe(\Modules\Finance\Observers\PurchaseBillJournalObserver::class);
        \Modules\Purchase\Models\PurchasePayment::observe(\Modules\Finance\Observers\PurchasePaymentJournalObserver::class);
    }

    /**
     * Configure the proper event listeners for email verification.
     */
    protected function configureEmailVerification(): void {}
}
