<?php

namespace Modules\Outbound\Tests\Feature;

use App\Exceptions\UserFacingException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Outbound\Jobs\ProcessPicklistCompleteJob;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Models\PicklistItem;
use Modules\Outbound\Models\PicklistItemAllocation;
use Modules\Outbound\Services\OrderReleaseService;
use Modules\Outbound\Services\PicklistService;
use Modules\Outbound\Support\ActiveProcessOrderScope;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Sales\Models\SalesInvoice;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderItem;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\StockService;
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

    private PicklistItem $pickItem;

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

        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->variant->id,
            'location_id' => $this->location->id,
            'bin_id' => $this->bin->id,
            'on_hand' => 1,
            'on_order' => 0,
            'available' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->order = SalesOrder::create([
            'salesorder_no' => 'TT-585665956600644834',
            'channel_order_no' => '585665956600644834',
            'customer_name' => 'n***ng a***ni',
            'location_id' => $this->location->id,
            'status' => 'reserved',
            'is_paid' => true,
            'source' => null,
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

        $this->pickItem = PicklistItem::create([
            'picklist_id' => $this->picklist->id,
            'order_id' => $this->order->id,
            'order_item_id' => $orderItem->id,
            'item_id' => $this->variant->id,
            'sku' => $this->variant->sku,
            'qty_ordered' => 1,
            'qty_picked' => 1,
            'item_status' => PicklistItem::STATUS_COMPLETED,
        ]);

        app(StockService::class)->reserve(
            $this->variant->sku,
            (string) $this->variant->id,
            (string) $this->location->id,
            1,
            $this->order->salesorder_no,
        );

        PicklistItemAllocation::create([
            'picklist_item_id' => $this->pickItem->id,
            'bin_id' => $this->bin->id,
            'qty' => 1,
            'physical_committed_qty' => 0,
            'picked_at' => now(),
            'picked_by' => $this->user->id,
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
        $this->assertSame(0, (int) DB::table('inventories')->where('bin_id', $this->bin->id)->value('on_hand'));
        $this->assertSame(0, (int) DB::table('inventories')->where('bin_id', $this->bin->id)->value('on_order'));
        $this->assertSame(0, (int) DB::table('inventories')
            ->where('item_id', $this->variant->id)
            ->where('location_id', $this->location->id)
            ->whereNull('bin_id')
            ->value('on_order'));
        $this->assertSame(1, (int) DB::table('inventory_movements')
            ->where('transaction_number', $this->order->salesorder_no)
            ->where('source', 'ORDER_RELEASE')
            ->count());
        $this->assertSame(1, (int) PicklistItemAllocation::query()
            ->where('picklist_item_id', $this->pickItem->id)
            ->value('physical_committed_qty'));
        $this->assertDatabaseHas('inventory_movements', [
            'transaction_number' => $invoice->invoice_number,
            'reference_number' => $this->order->channel_order_no,
            'source' => 'INVOICE',
            'qty' => -1,
            'bin_id' => $this->bin->id,
        ]);

        app(OrderReleaseService::class)->releaseIfComplete(
            $this->picklist->fresh(),
            (string) $this->order->id,
        );

        $this->assertSame(1, SalesInvoice::query()->where('order_id', $this->order->id)->count());
        $this->assertSame(1, DB::table('inventory_movements')->where('source', 'INVOICE')->count());
        $this->assertSame(0, (int) DB::table('inventories')->where('bin_id', $this->bin->id)->value('on_hand'));

        $pdfRes = $this->get("/api/v1/sales/{$this->order->id}/invoice");
        $pdfRes->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $stageRes = $this->getJson('/api/v1/outbound/orders/finish-pick');
        $stageRes->assertOk()
            ->assertJsonPath('data.0.id', $this->order->id)
            ->assertJsonPath('data.0.invoice_no', $invoice->invoice_number)
            ->assertJsonPath('meta.total', 1);

        $secondComplete = $this->postJson("/api/v1/outbound/picklists/{$this->picklist->id}/complete");

        $secondComplete->assertOk()
            ->assertJsonPath('data.status', Picklist::STATUS_COMPLETED);
        $this->assertSame(1, SalesInvoice::query()->where('order_id', $this->order->id)->count());
        $this->assertSame(1, DB::table('inventory_movements')->where('source', 'INVOICE')->count());
    }

    public function test_manual_completion_finishes_invoice_and_stock_before_async_safety_job(): void
    {
        $this->actingAs($this->user);
        Queue::fake();

        $res = $this->postJson("/api/v1/outbound/picklists/{$this->picklist->id}/complete");

        $res->assertOk();
        $this->assertSame('picked', $this->order->fresh()->status);
        $this->assertDatabaseHas('sales_invoices', [
            'order_id' => $this->order->id,
        ]);
        $this->assertSame(1, (int) PicklistItemAllocation::query()
            ->where('picklist_item_id', $this->pickItem->id)
            ->value('physical_committed_qty'));
        $this->assertDatabaseHas('inventory_movements', [
            'source' => 'INVOICE',
            'qty' => -1,
        ]);
        Queue::assertPushed(ProcessPicklistCompleteJob::class, 1);
        Queue::assertPushed(ProcessPicklistCompleteJob::class, function (ProcessPicklistCompleteJob $job): bool {
            return $job->queue === config('queue.routing.warehouse_safety.queue')
                && $job->connection === config('queue.routing.warehouse_safety.connection');
        });
    }

    public function test_stage_endpoint_returns_flat_paginated_data(): void
    {
        $this->actingAs($this->user);

        $response = $this->getJson('/api/v1/outbound/orders/finish-pick');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.total', 0);

        $this->assertIsArray($response->json('data'));
    }

    public function test_finish_pick_does_not_show_order_until_picklist_is_completed(): void
    {
        $this->actingAs($this->user);
        $this->order->update(['status' => 'picked']);

        $response = $this->getJson('/api/v1/outbound/orders/finish-pick');

        $response->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->picklist->update([
            'status' => Picklist::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        $completedResponse = $this->getJson('/api/v1/outbound/orders/finish-pick');

        $completedResponse->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $this->order->id)
            ->assertJsonPath('data.0.picklist_id', $this->picklist->id)
            ->assertJsonPath('data.0.picklist_no', $this->picklist->picklist_no)
            ->assertJsonPath('data.0.picker_name', $this->user->name);
    }

    public function test_packing_belum_scope_does_not_export_an_in_progress_picklist(): void
    {
        $scope = app(ActiveProcessOrderScope::class);

        $this->order->update(['status' => 'picked']);

        $this->assertSame(
            0,
            $scope->apply(SalesOrder::query(), 'packing', 'belum')->count(),
        );

        $this->picklist->update([
            'status' => Picklist::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        $this->assertSame(
            1,
            $scope->apply(SalesOrder::query(), 'packing', 'belum')->count(),
        );
    }

    public function test_cancelling_after_finish_pick_restores_the_same_origin_bin_once(): void
    {
        $this->actingAs($this->user);

        $this->postJson("/api/v1/outbound/picklists/{$this->picklist->id}/complete")
            ->assertOk();

        $invoice = SalesInvoice::query()->where('order_id', $this->order->id)->firstOrFail();
        app(SalesOrderService::class)->cancelLocally(
            (string) $this->order->id,
            'Pengujian batal sebelum paket diserahkan ke kurir',
            (string) $this->user->id,
        );

        $this->assertSame(1, (int) DB::table('inventories')->where('bin_id', $this->bin->id)->value('on_hand'));
        $this->assertSame(0, (int) DB::table('inventories')->where('bin_id', $this->bin->id)->value('on_order'));
        $this->assertSame(0, (int) PicklistItemAllocation::query()
            ->where('picklist_item_id', $this->pickItem->id)
            ->value('physical_committed_qty'));
        $this->assertDatabaseHas('inventory_movements', [
            'transaction_number' => $invoice->invoice_number,
            'source' => 'INVOICE',
            'qty' => -1,
            'bin_id' => $this->bin->id,
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'transaction_number' => $this->order->salesorder_no,
            'source' => 'ORDER_RESTORE_CANCEL',
            'qty' => 1,
            'bin_id' => $this->bin->id,
        ]);
    }

    public function test_last_picked_item_automatically_completes_picklist(): void
    {
        $item = $this->picklist->items()->firstOrFail();
        PicklistItemAllocation::query()
            ->where('picklist_item_id', $item->id)
            ->delete();
        $item->update([
            'qty_picked' => 0,
            'item_status' => null,
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

    public function test_last_picked_item_finishes_completion_before_queue_safety_job(): void
    {
        Queue::fake();

        $item = $this->picklist->items()->firstOrFail();
        PicklistItemAllocation::query()
            ->where('picklist_item_id', $item->id)
            ->delete();
        $item->update([
            'qty_picked' => 0,
            'item_status' => null,
        ]);

        app(PicklistService::class)->pickItem($this->picklist->id, $item->id, [
            'qty_delta' => 1,
            'bin_code' => $this->bin->bin_final_code,
        ]);

        Queue::assertPushed(ProcessPicklistCompleteJob::class, 1);
        Queue::assertPushed(ProcessPicklistCompleteJob::class, function (ProcessPicklistCompleteJob $job): bool {
            return $job->queue === config('queue.routing.warehouse_safety.queue')
                && $job->connection === config('queue.routing.warehouse_safety.connection');
        });
        $this->assertSame('picked', $this->order->fresh()->status);
        $this->assertDatabaseCount('sales_invoices', 1);
        $this->assertSame(1, (int) PicklistItemAllocation::query()
            ->where('picklist_item_id', $item->id)
            ->value('physical_committed_qty'));
        $this->assertDatabaseCount('inventory_movements', 3);
        $this->assertDatabaseHas('inventory_movements', [
            'transaction_number' => $this->order->salesorder_no,
            'source' => 'ORDER_RELEASE',
            'qty' => -1,
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'source' => 'INVOICE',
        ]);
    }

    public function test_move_to_ready_refuses_a_completed_picklist(): void
    {
        $this->picklist->update([
            'status' => Picklist::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
        $this->order->update([
            'handed_to_warehouse_at' => now(),
            'pick_failed_at' => now(),
        ]);

        $this->expectException(UserFacingException::class);

        app(SalesOrderService::class)
            ->moveToReadyToProcess([(string) $this->order->id], $this->user);
    }
}
