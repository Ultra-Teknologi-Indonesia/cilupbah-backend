<?php

namespace Modules\Webhook\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Inventory;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Sales\Models\SalesInvoice;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesPayment;
use Modules\Sales\Models\SalesReturn;
use Modules\Inventory\Models\InventoryTransfer;
use Modules\Webhook\Jobs\DispatchWebhookEventJob;
use Modules\Webhook\Models\WebhookSubscription;
use Modules\Webhook\Observers\InvoiceWebhookObserver;
use Modules\Webhook\Observers\PaymentWebhookObserver;
use Modules\Webhook\Observers\PurchaseOrderWebhookObserver;
use Modules\Webhook\Observers\SalesOrderWebhookObserver;
use Modules\Webhook\Observers\SalesReturnWebhookObserver;
use Modules\Webhook\Observers\StockTransferWebhookObserver;
use Modules\Webhook\Services\WebhookDispatcherService;
use Modules\Webhook\Support\WebhookEvent;
use Tests\TestCase;

/**
 * Membuktikan tiap observer memancarkan event webhook yang BENAR.
 * - Observer 'created' diuji via pemanggilan langsung + model in-memory.
 * - Observer 'updated' (price/stock) diuji via update model nyata agar wasChanged() valid.
 */
class WebhookObserverTest extends TestCase
{
    use RefreshDatabase;

    private function subscribe(string $event): void
    {
        WebhookSubscription::create([
            'event' => $event,
            'target_url' => 'https://example.test/hook',
            'secret' => 'sec',
            'is_active' => true,
        ]);
        WebhookDispatcherService::flushCache();
    }

    private function assertEmits(string $event, callable $fire): void
    {
        $this->subscribe($event);
        Queue::fake();
        $fire();
        Queue::assertPushed(DispatchWebhookEventJob::class, fn ($job) => $job->event === $event);
    }

    private function uuid(): string
    {
        return (string) Str::uuid7();
    }

    // ── created observers (pemanggilan langsung) ──

    public function test_invoice_observer_emits_invoice(): void
    {
        $this->assertEmits(WebhookEvent::INVOICE, function () {
            $m = new SalesInvoice(['invoice_number' => 'INV-1', 'customer_name' => 'A']);
            $m->id = $this->uuid();
            (new InvoiceWebhookObserver())->created($m);
        });
    }

    public function test_payment_observer_emits_payment(): void
    {
        $this->assertEmits(WebhookEvent::PAYMENT, function () {
            $m = new SalesPayment(['payment_number' => 'PAY-1', 'amount' => 1000]);
            $m->id = $this->uuid();
            (new PaymentWebhookObserver())->created($m);
        });
    }

    public function test_purchaseorder_observer_emits_purchaseorder(): void
    {
        $this->assertEmits(WebhookEvent::PURCHASE_ORDER, function () {
            $m = new PurchaseOrder(['po_number' => 'PO-1', 'status' => 'draft', 'total_amount' => 500]);
            $m->id = $this->uuid();
            (new PurchaseOrderWebhookObserver())->created($m);
        });
    }

    public function test_salesreturn_observer_emits_salesreturn(): void
    {
        $this->assertEmits(WebhookEvent::SALES_RETURN, function () {
            $m = new SalesReturn(['return_number' => 'RET-1', 'customer_name' => 'A', 'status' => 'open']);
            $m->id = $this->uuid();
            (new SalesReturnWebhookObserver())->created($m);
        });
    }

    public function test_stocktransfer_observer_emits_stocktransfer(): void
    {
        $this->assertEmits(WebhookEvent::STOCK_TRANSFER, function () {
            $m = new InventoryTransfer(['transfer_number' => 'TRF-1', 'status' => 'draft']);
            $m->id = $this->uuid();
            (new StockTransferWebhookObserver())->created($m);
        });
    }

    public function test_salesorder_observer_emits_salesorder(): void
    {
        $this->assertEmits(WebhookEvent::SALES_ORDER, function () {
            $m = new SalesOrder(['salesorder_no' => 'SO-1', 'status' => 'new', 'grand_total' => 100]);
            $m->id = $this->uuid();
            (new SalesOrderWebhookObserver())->created($m);
        });
    }

    // ── updated observers (model nyata agar wasChanged valid) ──

    public function test_price_observer_emits_on_sell_price_change(): void
    {
        $category = Category::create(['name' => 'C', 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'P', 'status' => 'master', 'is_active' => true]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'V1', 'sell_price' => 100, 'is_active' => true]);

        $this->subscribe(WebhookEvent::PRICE);
        Queue::fake();

        $variant->update(['sell_price' => 250]);

        Queue::assertPushed(DispatchWebhookEventJob::class, fn ($job) => $job->event === WebhookEvent::PRICE);
    }

    public function test_stock_observer_emits_on_on_hand_change(): void
    {
        $location = \Modules\Warehouse\Models\Location::factory()->create();
        $category = Category::create(['name' => 'C', 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'P', 'status' => 'master', 'is_active' => true]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'V1', 'sell_price' => 100, 'is_active' => true]);
        $inventory = Inventory::create([
            'item_id' => $variant->id, 'location_id' => $location->id, 'bin_id' => null,
            'on_hand' => 0, 'on_order' => 0, 'reserved' => 0, 'available' => 0,
        ]);

        $this->subscribe(WebhookEvent::STOCK);
        Queue::fake();

        $inventory->update(['on_hand' => 10, 'available' => 10]);

        Queue::assertPushed(DispatchWebhookEventJob::class, fn ($job) => $job->event === WebhookEvent::STOCK);
    }
}
