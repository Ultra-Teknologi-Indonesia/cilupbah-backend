<?php

namespace Modules\Inbound\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Inbound\Models\Inbound;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

class AutoCreateInboundFromPOTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;
    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->location = Location::create([
            'location_code' => 'WH-PO', 'location_name' => 'Gudang PO',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);
        LocationBin::create([
            'location_id' => $this->location->id, 'bin_code' => 'IN', 'bin_final_code' => 'WH-PO-IN', 'is_inbound' => true,
        ]);

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Cat PO', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId, 'name' => 'P-PO', 'sku' => 'P-PO', 'is_active' => true,
        ]);
        $this->variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'V-PO']);
    }

    private function makePO(string $status = PurchaseOrder::STATUS_DRAFT): PurchaseOrder
    {
        $contact = \Modules\Supplier\Models\Contact::create([
            'code' => 'SUP-' . fake()->unique()->numerify('#####'),
            'name' => 'Supplier ' . fake()->unique()->numerify('####'),
            'is_supplier' => true,
        ]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-' . fake()->unique()->numerify('######'),
            'location_id' => $this->location->id,
            'contact_id' => $contact->id,
            'status' => $status,
            'order_date' => now(),
            'created_by' => 'admin',
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $this->variant->id,
            'qty' => 100,
            'received_qty' => 0,
            'landed_cost_per_unit' => 10000,
        ]);

        return $po->fresh('items');
    }

    public function test_po_open_triggers_inbound_draft_auto_create(): void
    {
        $po = $this->makePO(PurchaseOrder::STATUS_DRAFT);
        $this->assertDatabaseCount('inbounds', 0);

        $po->update(['status' => PurchaseOrder::STATUS_OPEN]);

        $inbound = Inbound::where('source_id', $po->id)->first();
        $this->assertNotNull($inbound);
        $this->assertEquals(Inbound::STATUS_DRAFT, $inbound->status);
        $this->assertEquals(100, $inbound->items->first()->expected_qty);
    }

    public function test_po_open_twice_does_not_double_create(): void
    {
        $po = $this->makePO(PurchaseOrder::STATUS_OPEN);
        $this->assertEquals(1, Inbound::where('source_id', $po->id)->count());

        $po->update(['notes' => 'edited']);
        $po->update(['status' => PurchaseOrder::STATUS_OPEN]);

        $this->assertEquals(1, Inbound::where('source_id', $po->id)->count(), 'Idempoten — tidak double-create');
    }

    public function test_receive_additional_creates_new_inbound_for_remaining_qty(): void
    {
        $po = $this->makePO(PurchaseOrder::STATUS_OPEN);
        // Simulate 60 already received via PO items denorm.
        PurchaseOrderItem::where('purchase_order_id', $po->id)->update(['received_qty' => 60]);

        $inbound = app(\Modules\Inbound\Services\InboundService::class)
            ->createDraftFromPO($po->fresh(), 'admin', isAdditional: true);

        $this->assertEquals(40, $inbound->items->first()->expected_qty);
        $this->assertEquals(2, Inbound::where('source_id', $po->id)->count());
    }
}
