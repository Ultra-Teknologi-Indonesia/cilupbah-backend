<?php

namespace Modules\Webhook\Providers;

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
     * Daftarkan observer webhook (non-invasif) ke model domain milik Rasyid/Darriel.
     * Observer hanya MEMBACA model + memicu dispatcher (afterCommit) — tanpa mengubah modul lain.
     */
    public function boot(): void
    {
        parent::boot();

        // Fase 1 — event TikTok-driven
        \Modules\Product\Models\Product::observe(\Modules\Webhook\Observers\ProductWebhookObserver::class);
        \Modules\Product\Models\ProductVariant::observe(\Modules\Webhook\Observers\VariantWebhookObserver::class);
        \Modules\Inventory\Models\Inventory::observe(\Modules\Webhook\Observers\InventoryWebhookObserver::class);
        \Modules\Sales\Models\SalesOrder::observe(\Modules\Webhook\Observers\SalesOrderWebhookObserver::class);

        // Fase 2 — event akuntansi/ops
        \Modules\Sales\Models\SalesInvoice::observe(\Modules\Webhook\Observers\InvoiceWebhookObserver::class);
        \Modules\Sales\Models\SalesPayment::observe(\Modules\Webhook\Observers\PaymentWebhookObserver::class);
        \Modules\Purchase\Models\PurchaseOrder::observe(\Modules\Webhook\Observers\PurchaseOrderWebhookObserver::class);
        \Modules\Sales\Models\SalesReturn::observe(\Modules\Webhook\Observers\SalesReturnWebhookObserver::class);
        \Modules\Inventory\Models\InventoryTransfer::observe(\Modules\Webhook\Observers\StockTransferWebhookObserver::class);
    }

    /**
     * Configure the proper event listeners for email verification.
     */
    protected function configureEmailVerification(): void {}
}
