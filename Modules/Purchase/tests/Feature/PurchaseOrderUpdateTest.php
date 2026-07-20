<?php

namespace Modules\Purchase\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Modules\Inbound\Models\Inbound;
use Modules\Inbound\Models\InboundItem;
use Modules\Inbound\Models\InboundParticipant;
use Modules\Inbound\Services\InboundService;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\Putaway;
use Modules\Inventory\Models\PutawayItem;
use Modules\Inventory\Models\PutawayItemSource;
use Modules\Inventory\Models\PutawayPlacement;
use Modules\Inventory\Models\PutawaySource;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Purchase\Enums\PurchaseActivityAction;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderActivity;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Purchase\Services\PurchaseOrderService;
use Modules\Supplier\Models\Contact;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

class PurchaseOrderUpdateTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;
    private ProductVariant $variantA;
    private ProductVariant $variantB;
    private Contact $contact;
    private \App\Models\User $actor;
    private PurchaseOrderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->actor = \App\Models\User::factory()->create(['name' => 'Petugas Uji']);
        $this->actor->givePermissionTo(
            \App\Models\Permission::findOrCreate('view-transaksi-pembelian', 'web')
        );
        $this->actingAs($this->actor);

        $this->service = app(PurchaseOrderService::class);

        $this->location = Location::create([
            'location_code' => 'WH-EDIT', 'location_name' => 'Gudang Edit',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);
        LocationBin::create([
            'location_id' => $this->location->id, 'bin_code' => 'IN',
            'bin_final_code' => 'WH-EDIT-IN', 'is_inbound' => true,
        ]);

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Cat Edit', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId, 'name' => 'P-Edit', 'sku' => 'P-EDIT', 'is_active' => true,
        ]);
        $this->variantA = ProductVariant::create(['product_id' => $product->id, 'sku' => 'V-EDIT-A']);
        $this->variantB = ProductVariant::create(['product_id' => $product->id, 'sku' => 'V-EDIT-B']);

        $this->contact = Contact::create([
            'code' => 'SUP-EDIT', 'name' => 'Supplier Edit', 'is_supplier' => true,
        ]);
    }

    private function makePO(string $status, int $qty = 100, int $receivedQty = 0): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'po_number'   => 'PO-' . fake()->unique()->numerify('######'),
            'location_id' => $this->location->id,
            'contact_id'  => $this->contact->id,
            'status'      => PurchaseOrder::STATUS_DRAFT,
            'order_date'  => now(),
            'created_by'  => 'admin',
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id'           => $this->variantA->id,
            'qty'               => $qty,
            'received_qty'      => 0,
            'unit_price'        => 1000,
        ]);

        app(InboundService::class)->createDraftFromPO($po->fresh('items'), 'admin');

        DB::table('purchase_orders')->where('id', $po->id)->update([
            'status' => PurchaseOrder::STATUS_OPEN,
        ]);

        $po = $po->fresh('items');

        if ($receivedQty > 0) {
            $this->service->receive($po->id, [
                'items' => [[
                    'purchase_order_item_id' => $po->items->first()->id,
                    'qty'                    => $receivedQty,
                ]],
            ]);
            $po = $po->fresh('items');
        }

        if ($po->status !== $status) {
            DB::table('purchase_orders')->where('id', $po->id)->update(['status' => $status]);
        }

        return $po->fresh('items');
    }

    /**
     * Bangun putaway COMPLETED yang menempatkan $qty unit varian A ke rak tujuan,
     * lengkap dengan pivot putaway_item_sources supaya jalur reversal bisa
     * menelusurinya balik ke baris inbound.
     */
    private function makePutawayCovering(PurchaseOrder $po, int $qty): Putaway
    {
        $inboundItem = InboundItem::query()
            ->join('inbounds', 'inbounds.id', '=', 'inbound_items.inbound_id')
            ->where('inbounds.source_id', $po->id)
            ->where('inbounds.status', '!=', Inbound::STATUS_CANCELLED)
            ->where('inbound_items.item_id', $this->variantA->id)
            ->where('inbound_items.received_qty', '>', 0)
            ->select('inbound_items.*')
            ->firstOrFail();
        $inbound = Inbound::findOrFail($inboundItem->inbound_id);

        $sourceBin = LocationBin::where('location_id', $this->location->id)
            ->where('is_inbound', true)
            ->firstOrFail();
        $destBin = LocationBin::create([
            'location_id'    => $this->location->id,
            'bin_code'       => 'A1',
            'bin_final_code' => 'WH-EDIT-A1',
        ]);

        $putaway = Putaway::create([
            'putaway_no'  => 'PTW-' . fake()->unique()->numerify('#####'),
            'location_id' => $this->location->id,
            'source_type' => 'INBOUND',
            'source_id'   => $inbound->id,
            'status'      => Putaway::STATUS_COMPLETED,
            'created_by'  => 'admin',
        ]);
        PutawaySource::create(['putaway_id' => $putaway->id, 'inbound_id' => $inbound->id]);

        $putawayItem = PutawayItem::create([
            'putaway_id'         => $putaway->id,
            'item_id'            => $this->variantA->id,
            'source_bin_id'      => $sourceBin->id,
            'destination_bin_id' => $destBin->id,
            'qty'                => $qty,
            'putaway_qty'        => $qty,
        ]);
        PutawayPlacement::create([
            'putaway_item_id' => $putawayItem->id,
            'bin_id'          => $destBin->id,
            'qty'             => $qty,
        ]);
        PutawayItemSource::create([
            'putaway_item_id' => $putawayItem->id,
            'inbound_item_id' => $inboundItem->id,
            'qty'             => $qty,
            'putaway_qty'     => $qty,
        ]);

        \Modules\Inventory\Models\Inventory::where('item_id', $this->variantA->id)
            ->update(['bin_id' => $destBin->id]);

        return $putaway;
    }

    private function onHandFor(ProductVariant $variant): int
    {
        return (int) \Modules\Inventory\Models\Inventory::where('item_id', $variant->id)
            ->sum('on_hand');
    }

    private function payload(PurchaseOrder $po, array $items): array
    {
        return [
            'contact_id'  => $po->contact_id,
            'location_id' => $po->location_id,
            'order_date'  => $po->order_date->toDateString(),
            'items'       => $items,
        ];
    }

    private function line(PurchaseOrderItem $item, array $overrides = []): array
    {
        return array_merge([
            'id'         => $item->id,
            'item_id'    => $item->item_id,
            'qty'        => (int) $item->qty,
            'unit_price' => (float) $item->unit_price,
            'disc'       => 0,
        ], $overrides);
    }

    public function test_edit_partial_received_preserves_row_id_and_received_qty(): void
    {
        $po = $this->makePO(PurchaseOrder::STATUS_PARTIAL_RECEIVED, qty: 100, receivedQty: 40);
        $item = $po->items->first();

        $this->service->update($po->id, $this->payload($po, [
            $this->line($item, ['qty' => 150, 'unit_price' => 1200]),
        ]));

        $item->refresh();

        $this->assertSame(150, (int) $item->qty);
        $this->assertSame(40, (int) $item->received_qty);
        $this->assertEquals(1200, (float) $item->unit_price);
        $this->assertSame(1, PurchaseOrderItem::where('purchase_order_id', $po->id)->count());
    }

    public function test_reducing_qty_below_received_reverses_the_receipt(): void
    {
        $po = $this->makePO(PurchaseOrder::STATUS_PARTIAL_RECEIVED, qty: 100, receivedQty: 40);
        $item = $po->items->first();

        $this->assertSame(40, $this->onHandFor($this->variantA));

        $this->service->update($po->id, $this->payload($po, [
            $this->line($item, ['qty' => 10]),
        ]));

        $item->refresh();

        $this->assertSame(10, (int) $item->qty);
        $this->assertSame(10, (int) $item->received_qty, 'received_qty harus ikut turun');
        $this->assertSame(10, $this->onHandFor($this->variantA), 'stok fisik harus ditarik balik');
        $this->assertSame(10, (int) InboundItem::where('item_id', $this->variantA->id)
            ->sum('received_qty'), 'baris inbound harus ikut turun');
    }

    public function test_reversal_writes_purchase_reversal_movement(): void
    {
        $po = $this->makePO(PurchaseOrder::STATUS_PARTIAL_RECEIVED, qty: 100, receivedQty: 40);
        $item = $po->items->first();

        $this->service->update($po->id, $this->payload($po, [
            $this->line($item, ['qty' => 10]),
        ]));

        $movement = InventoryMovement::where('item_id', $this->variantA->id)
            ->where('source', 'PURCHASE_REVERSAL')
            ->first();

        $this->assertNotNull($movement, 'harus ada movement PURCHASE_REVERSAL');
        $this->assertSame(-30, (int) $movement->qty);
    }

    public function test_deleting_received_item_reverses_and_removes_the_line(): void
    {
        $po = $this->makePO(PurchaseOrder::STATUS_PARTIAL_RECEIVED, qty: 100, receivedQty: 40);
        $item = $po->items->first();

        $this->service->update($po->id, $this->payload($po, [
            ['item_id' => $this->variantB->id, 'qty' => 5, 'unit_price' => 500, 'disc' => 0],
        ]));

        $this->assertDatabaseMissing('purchase_order_items', ['id' => $item->id]);
        $this->assertSame(0, $this->onHandFor($this->variantA), 'stok varian yang dihapus harus nol');
        $this->assertSame(1, PurchaseOrderItem::where('purchase_order_id', $po->id)->count());
    }

    public function test_reversal_blocked_when_stock_already_gone(): void
    {
        $po = $this->makePO(PurchaseOrder::STATUS_PARTIAL_RECEIVED, qty: 100, receivedQty: 40);
        $item = $po->items->first();

        InboundItem::where('item_id', $this->variantA->id)
            ->update(['putaway_qty' => 40]);

        $this->assertSame(40, $this->onHandFor($this->variantA));

        $putaway = $this->makePutawayCovering($po, 40);
        \Modules\Inventory\Models\Inventory::where('item_id', $this->variantA->id)
            ->update(['on_hand' => 5]);

        try {
            $this->service->update($po->id, $this->payload($po, [
                $this->line($item, ['qty' => 0 + 10]),
            ]));
            $this->fail('Penarikan stok yang sudah tidak ada seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('V-EDIT-A', implode(' ', $e->errors()['items']));
        }

        $this->assertSame(100, (int) $item->fresh()->qty, 'PO tidak boleh berubah saat ditolak');
        $this->assertNotNull($putaway);
    }

    public function test_deleting_untouched_item_succeeds(): void
    {
        $po = $this->makePO(PurchaseOrder::STATUS_OPEN, qty: 100, receivedQty: 0);
        $item = $po->items->first();

        $extra = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id'           => $this->variantB->id,
            'qty'               => 7,
            'received_qty'      => 0,
            'unit_price'        => 500,
        ]);

        $this->service->update($po->id, $this->payload($po, [
            $this->line($item),
        ]));

        $this->assertDatabaseMissing('purchase_order_items', ['id' => $extra->id]);
        $this->assertDatabaseHas('purchase_order_items', ['id' => $item->id]);
    }

    public function test_adding_item_resyncs_draft_inbound(): void
    {
        $po = $this->makePO(PurchaseOrder::STATUS_PARTIAL_RECEIVED, qty: 100, receivedQty: 40);
        $item = $po->items->first();
        $inbound = Inbound::where('source_id', $po->id)->firstOrFail();

        $this->assertSame(100, (int) InboundItem::where('inbound_id', $inbound->id)
            ->where('item_id', $this->variantA->id)->value('expected_qty'));

        $this->service->update($po->id, $this->payload($po, [
            $this->line($item, ['qty' => 250]),
            ['item_id' => $this->variantB->id, 'qty' => 30, 'unit_price' => 500, 'disc' => 0],
        ]));

        $this->assertSame(250, (int) InboundItem::where('inbound_id', $inbound->id)
            ->where('item_id', $this->variantA->id)->value('expected_qty'));
        $this->assertSame(30, (int) InboundItem::where('inbound_id', $inbound->id)
            ->where('item_id', $this->variantB->id)->value('expected_qty'));
    }

    public function test_increasing_qty_on_fully_received_drops_to_partial(): void
    {
        $po = $this->makePO(PurchaseOrder::STATUS_FULLY_RECEIVED, qty: 100, receivedQty: 100);
        $item = $po->items->first();

        $this->service->update($po->id, $this->payload($po, [
            $this->line($item, ['qty' => 180]),
        ]));

        $this->assertSame(PurchaseOrder::STATUS_PARTIAL_RECEIVED, $po->fresh()->status);
    }

    public function test_edit_blocked_when_participant_active(): void
    {
        $po = $this->makePO(PurchaseOrder::STATUS_PARTIAL_RECEIVED, qty: 100, receivedQty: 40);
        $item = $po->items->first();
        $inbound = Inbound::where('source_id', $po->id)->firstOrFail();

        $user = \App\Models\User::factory()->create();
        InboundParticipant::create([
            'inbound_id' => $inbound->id,
            'user_id'    => $user->id,
            'status'     => InboundParticipant::STATUS_ACTIVE,
        ]);

        $this->expectException(\App\Exceptions\MobileSessionActiveException::class);

        $this->service->update($po->id, $this->payload($po, [
            $this->line($item, ['qty' => 150]),
        ]));
    }

    public function test_edit_records_who_did_what(): void
    {
        $po = $this->makePO(PurchaseOrder::STATUS_PARTIAL_RECEIVED, qty: 100, receivedQty: 40);
        $item = $po->items->first();

        $this->service->update($po->id, array_merge(
            $this->payload($po, [
                $this->line($item, ['qty' => 10]),
                ['item_id' => $this->variantB->id, 'qty' => 5, 'unit_price' => 500, 'disc' => 0],
            ]),
            ['ref_no' => 'REF-BARU'],
        ));

        $activities = PurchaseOrderActivity::where('purchase_order_id', $po->id)->get();

        foreach ($activities as $activity) {
            $this->assertSame($this->actor->id, $activity->actor_id, 'setiap baris riwayat harus punya pelaku');
            $this->assertSame('Petugas Uji', $activity->actor_name);
        }

        $actions = $activities->pluck('action')->map(fn ($a) => $a->value)->all();

        $this->assertContains('ITEM_ADDED', $actions, 'produk baru harus tercatat');
        $this->assertContains('ITEM_CHANGED', $actions, 'perubahan qty harus tercatat');
        $this->assertContains('RECEIPT_REVERSED', $actions, 'penarikan stok harus tercatat');
        $this->assertContains('FIELD_CHANGED', $actions, 'perubahan No. Ref harus tercatat');

        $reversal = $activities->firstWhere('action', PurchaseActivityAction::RECEIPT_REVERSED);
        $this->assertSame(30, $reversal->metadata['qty']);
        $this->assertSame('V-EDIT-A', $reversal->metadata['entity_no']);
    }

    public function test_removed_item_is_recorded_with_its_sku(): void
    {
        $po = $this->makePO(PurchaseOrder::STATUS_OPEN, qty: 100, receivedQty: 0);
        $item = $po->items->first();

        $this->service->update($po->id, $this->payload($po, [
            ['item_id' => $this->variantB->id, 'qty' => 5, 'unit_price' => 500, 'disc' => 0],
        ]));

        $removed = PurchaseOrderActivity::where('purchase_order_id', $po->id)
            ->where('action', PurchaseActivityAction::ITEM_REMOVED->value)
            ->first();

        $this->assertNotNull($removed);
        $this->assertSame('V-EDIT-A', $removed->metadata['entity_no']);
        $this->assertSame($item->id, $removed->entity_id);
    }

    public function test_activities_endpoint_returns_history(): void
    {
        $po = $this->makePO(PurchaseOrder::STATUS_OPEN, qty: 100, receivedQty: 0);
        $item = $po->items->first();

        $this->service->update($po->id, $this->payload($po, [
            $this->line($item, ['qty' => 120]),
        ]));

        $response = $this->getJson("/api/v1/purchase/orders/{$po->id}/activities");

        $response->assertOk();
        $this->assertNotEmpty($response->json('data'));
        $this->assertSame('Petugas Uji', $response->json('data.0.actor_name'));
    }

    public function test_open_po_can_be_deleted(): void
    {
        $po = $this->makePO(PurchaseOrder::STATUS_OPEN, qty: 100, receivedQty: 0);

        $this->assertTrue($this->service->delete($po->id));
        $this->assertDatabaseMissing('purchase_orders', ['id' => $po->id]);
    }

    public function test_deleting_received_po_reverses_receipts_and_detaches_inbound(): void
    {
        $po = $this->makePO(PurchaseOrder::STATUS_OPEN, qty: 100, receivedQty: 100);

        $this->assertTrue($this->service->delete($po->id));

        $this->assertDatabaseMissing('purchase_orders', ['id' => $po->id]);
        $this->assertDatabaseMissing('inbounds', ['source_id' => $po->id]);
        $this->assertDatabaseHas('inventory_movements', ['source' => 'PURCHASE_REVERSAL']);
    }
}
