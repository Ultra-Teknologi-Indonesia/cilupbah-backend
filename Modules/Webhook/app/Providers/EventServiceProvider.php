<?php

namespace Modules\Webhook\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{

    protected $listen = [];

    protected static $shouldDiscoverEvents = true;

    public function boot(): void
    {
        parent::boot();

        \Modules\Product\Models\Product::observe(\Modules\Webhook\Observers\ProductWebhookObserver::class);
        \Modules\Product\Models\ProductVariant::observe(\Modules\Webhook\Observers\VariantWebhookObserver::class);
        \Modules\Inventory\Models\Inventory::observe(\Modules\Webhook\Observers\InventoryWebhookObserver::class);
        \Modules\Sales\Models\SalesOrder::observe(\Modules\Webhook\Observers\SalesOrderWebhookObserver::class);

        \Modules\Sales\Models\SalesInvoice::observe(\Modules\Webhook\Observers\InvoiceWebhookObserver::class);
        \Modules\Sales\Models\SalesPayment::observe(\Modules\Webhook\Observers\PaymentWebhookObserver::class);
        \Modules\Purchase\Models\PurchaseOrder::observe(\Modules\Webhook\Observers\PurchaseOrderWebhookObserver::class);
        \Modules\Sales\Models\SalesReturn::observe(\Modules\Webhook\Observers\SalesReturnWebhookObserver::class);
        \Modules\Inventory\Models\InventoryTransfer::observe(\Modules\Webhook\Observers\StockTransferWebhookObserver::class);
    }

    protected function configureEmailVerification(): void {}
}
