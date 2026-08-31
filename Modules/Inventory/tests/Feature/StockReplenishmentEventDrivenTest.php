<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryTransfer;
use Modules\Inventory\Models\StockReplenishmentRequest;
use Modules\Inventory\Repositories\StockReplenishmentRepository;
use Modules\Inventory\Services\StockReplenishmentService;
use Modules\Notification\Services\NotificationDispatcher;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderItem;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

class StockReplenishmentEventDrivenTest extends TestCase
{
    use RefreshDatabase;

    private Location $main;

    private Location $small;

    private LocationBin $smallBin;

    private LocationBin $mainBin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        Location::query()->update(['is_warehouse' => false, 'is_small_warehouse' => false]);

        $this->main = Location::create([
            'location_code' => 'WH-TEST-MAIN',
            'location_name' => 'Gudang Pusat Test',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_small_warehouse' => false,
            'is_active' => true,
        ]);
        $this->small = Location::create([
            'location_code' => 'WH-TEST-SMALL',
            'location_name' => 'Gudang Kecil Test',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_small_warehouse' => true,
            'is_active' => true,
        ]);
        $this->smallBin = LocationBin::create([
            'location_id' => $this->small->id,
            'bin_code' => 'A-01',
            'bin_final_code' => 'WH-TEST-SMALL-A-01',
            'is_inbound' => false,
        ]);
        $this->mainBin = LocationBin::create([
            'location_id' => $this->main->id,
            'bin_code' => 'A-01',
            'bin_final_code' => 'WH-TEST-MAIN-A-01',
            'is_inbound' => false,
        ]);

        $dispatcher = \Mockery::mock(NotificationDispatcher::class);
        $dispatcher->shouldReceive('toPermission')->zeroOrMoreTimes();
        $dispatcher->shouldReceive('toUser')->zeroOrMoreTimes();
        app()->instance(NotificationDispatcher::class, $dispatcher);
    }

    public function test_shortage_is_based_on_active_order_demand_not_negative_stock_magnitude(): void
    {
        $variant = $this->makeVariant('EVT-001');
        $this->makeInventory($variant, -14, 4);
        $this->makeOrder($variant, 4);

        $shortage = app(StockReplenishmentRepository::class)
            ->shortagesForLocation($this->small->id)
            ->get($variant->id);

        $this->assertNotNull($shortage);
        $this->assertSame(4, $shortage->needed);
        $this->assertSame(-18, $shortage->available);
        $this->assertSame(4, $shortage->shortage);
    }

    public function test_reconciliation_is_idempotent_and_reacts_to_order_cancellation(): void
    {
        $variant = $this->makeVariant('EVT-002');
        $this->makeInventory($variant, -14, 4);
        $order = $this->makeOrder($variant, 4);

        $this->assertSame($this->small->id, Location::getSmallWarehouseId());
        $this->assertSame($this->main->id, Location::getMainWarehouseId());
        $this->assertSame($this->small->id, $order->location_id);

        $service = app(StockReplenishmentService::class);
        $first = $service->reconcileAutoBatch();
        $this->assertCount(1, $first['shortages']);
        $this->assertNull($first['request']);
        $this->assertSame(0, StockReplenishmentRequest::where('status', StockReplenishmentRequest::STATUS_PENDING)->count());

        $this->actingAs($this->createPrivilegedUser(), 'sanctum')
            ->postJson('/api/v1/inventory/stock-replenishment/queue', [
                'item_ids' => [$variant->id],
            ])
            ->assertSuccessful();

        $service->reconcileAutoBatch();

        $request = StockReplenishmentRequest::query()
            ->where('status', StockReplenishmentRequest::STATUS_PENDING)
            ->firstOrFail();

        $this->assertSame(1, StockReplenishmentRequest::where('status', StockReplenishmentRequest::STATUS_PENDING)->count());
        $this->assertSame(1, $request->items()->count());
        $this->assertSame(4, (int) $request->items()->first()->qty);
        $this->assertSame(0, InventoryTransfer::count());

        $otherVariant = $this->makeVariant('EVT-002B');
        $this->makeInventory($otherVariant, 0, 0);
        $this->makeOrder($otherVariant, 2);
        $service->reconcileAutoBatch();
        $this->assertSame(1, $request->fresh()->items()->count());

        $order->update(['status' => 'cancelled', 'is_canceled' => true]);
        $service->reconcileAutoBatch();

        $this->assertSame(0, StockReplenishmentRequest::where('status', StockReplenishmentRequest::STATUS_PENDING)->count());
        $this->assertSame(1, StockReplenishmentRequest::where('status', StockReplenishmentRequest::STATUS_CANCELLED)->count());
        $this->assertSame(0, StockReplenishmentRequest::where('status', StockReplenishmentRequest::STATUS_CANCELLED)->firstOrFail()->items()->count());
    }

    public function test_monitor_selection_queues_one_pending_request_without_creating_transfer(): void
    {
        $variant = $this->makeVariant('EVT-003');
        $this->makeInventory($variant, 0, 0);
        $this->makeOrder($variant, 3);

        $response = $this->actingAs($this->createPrivilegedUser(), 'sanctum')
            ->postJson('/api/v1/inventory/stock-replenishment/queue', [
                'item_ids' => [$variant->id],
            ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.request.status', StockReplenishmentRequest::STATUS_PENDING)
            ->assertJsonPath('data.queued_item_ids.0', $variant->id);

        $this->assertSame(1, StockReplenishmentRequest::where('status', StockReplenishmentRequest::STATUS_PENDING)->count());
        $this->assertSame(1, StockReplenishmentRequest::firstOrFail()->items()->count());
        $this->assertSame(0, InventoryTransfer::count());

        $this->actingAs($this->createPrivilegedUser(), 'sanctum')
            ->getJson('/api/v1/inventory/monitor/out-of-stock?mode=dipesan&location_id='.$this->small->id)
            ->assertSuccessful()
            ->assertJsonPath('data.0.item_id', $variant->id)
            ->assertJsonPath('data.0.has_active_restock_request', true);
    }

    public function test_stock_receipt_recalculation_removes_now_covered_pending_line(): void
    {
        $variant = $this->makeVariant('EVT-005');
        $this->makeInventory($variant, -4, 4);
        $this->makeOrder($variant, 4);

        $service = app(StockReplenishmentService::class);
        $this->actingAs($this->createPrivilegedUser(), 'sanctum')
            ->postJson('/api/v1/inventory/stock-replenishment/queue', [
                'item_ids' => [$variant->id],
            ])
            ->assertSuccessful();
        $service->reconcileAutoBatch();
        $this->assertSame(1, StockReplenishmentRequest::where('status', StockReplenishmentRequest::STATUS_PENDING)->count());

        Inventory::where('item_id', $variant->id)
            ->where('location_id', $this->small->id)
            ->update(['on_hand' => 4, 'available' => 0]);
        $service->reconcileAutoBatch();

        $this->assertSame(0, StockReplenishmentRequest::where('status', StockReplenishmentRequest::STATUS_PENDING)->count());
        $this->assertSame(1, StockReplenishmentRequest::where('status', StockReplenishmentRequest::STATUS_CANCELLED)->count());
    }

    public function test_rejection_requires_reason_and_records_actor_and_timestamp(): void
    {
        $variant = $this->makeVariant('EVT-REJECT-001');
        $this->makeInventory($variant, 0, 0);
        $this->makeOrder($variant, 2);

        $user = $this->createPrivilegedUser(['name' => 'Penguji Penolak']);
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/inventory/stock-replenishment/queue', [
                'item_ids' => [$variant->id],
            ])
            ->assertSuccessful();

        $request = StockReplenishmentRequest::where('status', StockReplenishmentRequest::STATUS_PENDING)
            ->firstOrFail();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/inventory/stock-replenishment/{$request->id}/reject", [])
            ->assertStatus(422);

        $this->assertSame(
            StockReplenishmentRequest::STATUS_PENDING,
            $request->fresh()->status,
        );

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/inventory/stock-replenishment/{$request->id}/reject", [
                'reason' => 'Qty terlalu kecil untuk diproses.',
            ])
            ->assertOk()
            ->assertJsonPath('data.rejected_by_user_id', $user->id)
            ->assertJsonPath('data.rejected_by_name', 'Penguji Penolak')
            ->assertJsonPath('data.reject_reason', 'Qty terlalu kecil untuk diproses.')
            ->assertJsonPath('data.status', StockReplenishmentRequest::STATUS_REJECTED);

        $rejected = $request->fresh();
        $this->assertSame($user->id, $rejected->rejected_by_user_id);
        $this->assertNotNull($rejected->rejected_at);
        $this->assertSame('Qty terlalu kecil untuk diproses.', $rejected->reject_reason);
    }

    public function test_request_page_cannot_create_or_add_items_manually(): void
    {
        $variant = $this->makeVariant('EVT-006');
        $user = $this->createPrivilegedUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/inventory/stock-replenishment', [
                'from_location_id' => $this->main->id,
                'to_location_id' => $this->small->id,
                'items' => [[
                    'item_id' => $variant->id,
                    'sku' => $variant->sku,
                    'qty' => 1,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Permintaan hanya dapat dibuat dari Monitor Stok > Dipesan namun habis.',
            ]);

        $request = StockReplenishmentRequest::create([
            'from_location_id' => $this->main->id,
            'to_location_id' => $this->small->id,
            'status' => StockReplenishmentRequest::STATUS_PENDING,
            'source' => StockReplenishmentRequest::SOURCE_MONITOR,
            'requested_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/inventory/stock-replenishment/{$request->id}/items", [
                'item_id' => $variant->id,
                'qty' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'SKU hanya dapat ditambahkan dari Monitor Stok > Dipesan namun habis.',
            ]);

        $this->assertSame(0, $request->items()->count());
    }

    public function test_monitor_queue_rejects_items_that_are_not_dipesan_namun_habis(): void
    {
        $variant = $this->makeVariant('EVT-007');
        $this->makeInventory($variant, 1, 0);
        $this->makeOrder($variant, 3);

        $response = $this->actingAs($this->createPrivilegedUser(), 'sanctum')
            ->postJson('/api/v1/inventory/stock-replenishment/queue', [
                'item_ids' => [$variant->id],
            ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.queued_item_ids', [])
            ->assertJsonPath('data.skipped_item_ids.0', $variant->id);

        $this->assertSame(0, StockReplenishmentRequest::where('status', StockReplenishmentRequest::STATUS_PENDING)->count());
    }

    public function test_request_items_are_paginated_and_searchable(): void
    {
        $first = $this->makeVariant('EVT-ITEM-001');
        $second = $this->makeVariant('EVT-ITEM-002');
        $this->makeInventory($first, 0, 0);
        $this->makeInventory($second, 0, 0);
        $this->makeOrder($first, 1);
        $this->makeOrder($second, 1);

        $user = $this->createPrivilegedUser();
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/inventory/stock-replenishment/queue', [
                'item_ids' => [$first->id, $second->id],
            ])
            ->assertSuccessful();

        $request = StockReplenishmentRequest::where('status', StockReplenishmentRequest::STATUS_PENDING)
            ->firstOrFail();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/inventory/stock-replenishment/{$request->id}/items?search=EVT-ITEM-002&per_page=1")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.sku', 'EVT-ITEM-002')
            ->assertJsonPath('data.0.reason_detail.type', 'stock_shortage')
            ->assertJsonPath('data.0.reason_detail.demand_qty', 1);
    }

    public function test_request_items_can_be_filtered_by_channel_and_shop(): void
    {
        $channel = Channel::create([
            'code' => 'test-channel',
            'name' => 'Test Channel',
            'is_active' => true,
        ]);
        $shop = ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => 'TEST-SHOP-001',
            'shop_name' => 'Test Shop',
            'is_active' => true,
        ]);

        $matched = $this->makeVariant('EVT-FILTER-001');
        $unmatched = $this->makeVariant('EVT-FILTER-002');
        $this->makeInventory($matched, 0, 0);
        $this->makeInventory($unmatched, 0, 0);
        $matchedOrder = $this->makeOrder($matched, 1);
        $this->makeOrder($unmatched, 1);
        $matchedOrder->update(['channel_shop_id' => $shop->shop_id]);

        $user = $this->createPrivilegedUser();
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/inventory/stock-replenishment/queue', [
                'item_ids' => [$matched->id, $unmatched->id],
            ])
            ->assertSuccessful();

        $request = StockReplenishmentRequest::where('status', StockReplenishmentRequest::STATUS_PENDING)
            ->firstOrFail();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/inventory/stock-replenishment/{$request->id}/item-filters")
            ->assertOk()
            ->assertJsonPath('data.channels.0.value', 'test-channel')
            ->assertJsonPath('data.shops.0.value', 'TEST-SHOP-001');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/inventory/stock-replenishment/{$request->id}/items?channel=test-channel&shop_id=TEST-SHOP-001")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.sku', 'EVT-FILTER-001');
    }

    public function test_removing_last_pending_item_closes_request_and_allows_requeue(): void
    {
        $variant = $this->makeVariant('EVT-RETURN-001');
        $this->makeInventory($variant, 0, 0);
        $this->makeOrder($variant, 1);
        $user = $this->createPrivilegedUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/inventory/stock-replenishment/queue', [
                'item_ids' => [$variant->id],
            ])
            ->assertSuccessful();

        $request = StockReplenishmentRequest::where('status', StockReplenishmentRequest::STATUS_PENDING)
            ->firstOrFail();
        $itemId = $request->items()->firstOrFail()->id;

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/inventory/stock-replenishment/{$request->id}/items/{$itemId}")
            ->assertOk()
            ->assertJsonPath('data.status', StockReplenishmentRequest::STATUS_CANCELLED);

        $this->assertSame(0, StockReplenishmentRequest::where('status', StockReplenishmentRequest::STATUS_PENDING)->count());
        $this->assertSame(StockReplenishmentRequest::STATUS_CANCELLED, $request->fresh()->status);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/inventory/stock-replenishment/queue', [
                'item_ids' => [$variant->id],
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.queued_item_ids.0', $variant->id);
    }

    public function test_approval_is_the_boundary_that_creates_and_approves_transfer(): void
    {
        $variant = $this->makeVariant('EVT-004');
        $this->makeInventory($variant, 10, 0);
        Inventory::where('item_id', $variant->id)
            ->where('location_id', $this->small->id)
            ->update(['location_id' => $this->main->id, 'bin_id' => $this->mainBin->id]);

        $request = StockReplenishmentRequest::create([
            'from_location_id' => $this->main->id,
            'to_location_id' => $this->small->id,
            'status' => StockReplenishmentRequest::STATUS_PENDING,
            'source' => StockReplenishmentRequest::SOURCE_MONITOR,
            'requested_at' => now(),
        ]);
        $request->items()->create([
            'item_id' => $variant->id,
            'sku' => $variant->sku,
            'qty' => 3,
        ]);

        $this->assertSame(0, InventoryTransfer::count());
        $accepted = app(StockReplenishmentService::class)->accept($request->id);

        $this->assertSame(StockReplenishmentRequest::STATUS_ACCEPTED, $accepted->status);
        $this->assertNotNull($accepted->transfer_out_id);
        $this->assertSame(InventoryTransfer::STATUS_APPROVED, $accepted->transferOut->status);
        $this->assertSame(1, InventoryTransfer::count());
    }

    private function makeVariant(string $sku): ProductVariant
    {
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Event Driven '.$sku,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId,
            'name' => 'Product '.$sku,
            'sku' => 'P-'.$sku,
            'is_active' => true,
            'is_stored' => true,
            'is_bundle' => false,
        ]);

        return ProductVariant::create([
            'product_id' => $product->id,
            'sku' => $sku,
            'is_active' => true,
        ]);
    }

    private function makeInventory(ProductVariant $variant, int $onHand, int $onOrder): void
    {
        Inventory::create([
            'item_id' => $variant->id,
            'location_id' => $this->small->id,
            'bin_id' => $this->smallBin->id,
            'on_hand' => $onHand,
            'on_order' => $onOrder,
            'available' => $onHand - $onOrder,
            'avg_cost' => 100,
        ]);
    }

    private function makeOrder(ProductVariant $variant, int $qty): SalesOrder
    {
        $order = SalesOrder::factory()->create([
            'location_id' => $this->small->id,
            'status' => 'pending',
        ]);

        SalesOrderItem::create([
            'order_id' => $order->id,
            'item_id' => $variant->id,
            'sku' => $variant->sku,
            'qty_in_base' => $qty,
            'price' => 100,
            'amount' => 100 * $qty,
        ]);

        return $order;
    }
}
