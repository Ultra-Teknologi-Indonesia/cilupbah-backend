<?php

namespace Modules\Outbound\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Models\PicklistItem;
use Modules\Outbound\Services\PicklistService;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Sales\Models\SalesInvoice;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderItem;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

class PicklistCompleteAutoInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Location $location;
    private LocationBin $bin;
    private ProductVariant $variant;
    private SalesOrder $order;
    private Picklist $picklist;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createPrivilegedUser();

        $this->location = Location::create([
            'location_code' => 'WH-TEST',
            'location_name' => 'Gudang Utama',
            'location_type' => 'warehouse',
            'is_active' => true,
        ]);

        $this->bin = LocationBin::create([
            'location_id' => $this->location->id,
            'bin_code' => 'A-01',
            'bin_final_code' => 'WH-TEST-A-01',
        ]);

        $category = Category::create(['name' => 'Accessories']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Clear Magnetic Case',
            'sku' => 'CB-CASE-01',
            'is_active' => true,
        ]);
        $this->variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'CB-CASE-01',
            'price' => 65000,
        ]);

        $this->order = SalesOrder::create([
            'salesorder_no' => 'TT-585665956600644834',
            'channel_order_no' => '585665956600644834',
            'customer_name' => 'n***ng a***ni',
            'location_id' => $this->location->id,
            'status' => 'reserved',
            'is_paid' => true,
            'source' => 'tiktok',
            'sub_total' => 65000,
            'grand_total' => 65000,
        ]);

        $orderItem = SalesOrderItem::create([
            'order_id' => $this->order->id,
            'item_id' => $this->variant->id,
            'sku' => $this->variant->sku,
            'description' => 'Clear Magnetic Case - White',
            'qty_in_base' => 1,
            'price' => 65000,
            'amount' => 65000,
        ]);

        $this->picklist = Picklist::create([
            'picklist_no' => 'PICK-000000008',
            'location_id' => $this->location->id,
            'picker_id' => $this->user->id,
            'created_by' => $this->user->id,
            'status' => Picklist::STATUS_IN_PROGRESS,
            'started_at' => now(),
        ]);

        PicklistItem::create([
            'picklist_id' => $this->picklist->id,
            'order_id' => $this->order->id,
            'order_item_id' => $orderItem->id,
            'item_id' => $this->variant->id,
            'sku' => $this->variant->sku,
            'qty_ordered' => 1,
            'qty_picked' => 1,
            'item_status' => PicklistItem::STATUS_COMPLETED,
        ]);
    }

    public function test_complete_picklist_auto_generates_sales_invoice(): void
    {
        $this->actingAs($this->user);

        $this->assertDatabaseMissing('sales_invoices', [
            'order_id' => $this->order->id,
        ]);

        $res = $this->postJson("/api/v1/outbound/picklists/{$this->picklist->id}/complete");

        $res->assertOk();

        $this->order->refresh();
        $this->assertSame('picked', $this->order->status);

        $invoice = SalesInvoice::where('order_id', $this->order->id)->first();
        $this->assertNotNull($invoice);
        $this->assertStringStartsWith('INV-', $invoice->invoice_number);
        $this->assertSame('n***ng a***ni', $invoice->customer_name);
        $this->assertEquals(65000, $invoice->total_amount);

        $pdfRes = $this->get("/api/v1/sales/{$this->order->id}/invoice");
        $pdfRes->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $stageRes = $this->getJson('/api/v1/outbound/orders/finish-pick');
        $stageRes->assertOk()
            ->assertJsonPath('data.data.0.id', $this->order->id)
            ->assertJsonPath('data.data.0.invoice_no', $invoice->invoice_number);
    }

    public function test_last_picked_item_automatically_completes_picklist(): void
    {
        $this->order->update(['source' => null]);
        $item = $this->picklist->items()->firstOrFail();
        $item->update([
            'qty_picked' => 0,
            'item_status' => null,
        ]);

        DB::table('inventories')->insert([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'item_id' => $this->variant->id,
            'location_id' => $this->location->id,
            'bin_id' => $this->bin->id,
            'on_hand' => 1,
            'on_order' => 0,
            'available' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(PicklistService::class)->pickItem($this->picklist->id, $item->id, [
            'qty_delta' => 1,
            'bin_code' => $this->bin->bin_final_code,
        ]);

        $this->assertDatabaseHas('picklists', [
            'id' => $this->picklist->id,
            'status' => Picklist::STATUS_COMPLETED,
        ]);
    }
}
