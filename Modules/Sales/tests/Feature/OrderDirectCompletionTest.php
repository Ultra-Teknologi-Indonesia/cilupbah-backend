<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Channel\Jobs\SyncProductToChannelJob;
use Modules\Outbound\Models\Picklist;
use Modules\Sales\Jobs\CancelChannelOrderJob;
use Modules\Sales\Models\OrderBinAllocation;
use Modules\Sales\Models\OrderBuyerConfirmation;
use Modules\Sales\Services\BuyerConfirmationService;
use Modules\Sales\Services\OrderDirectCompletionService;
use Modules\Sales\Services\SalesOrderService;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class OrderDirectCompletionTest extends TestCase
{
    use RefreshDatabase;

    private string $userId;

    private string $locationId;

    private string $binA;

    private string $binB;

    private string $variantId;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->userId = $this->seedUser();
        $this->locationId = $this->seedLocation(Location::SYSTEM_KECIL_CODE);
        $this->binA = $this->seedBin('GK-A1-K1-P1');
        $this->binB = $this->seedBin('GK-A1-K1-P2');
        $this->variantId = $this->seedProductVariant('SKU-ODC-1');
    }

    private function service(): OrderDirectCompletionService
    {
        return app(OrderDirectCompletionService::class);
    }

    public function test_memotong_stok_dari_rak_terpilih_dan_menandai_selesai(): void
    {
        $order = $this->seedOrder(qty: 3);
        $this->seedStock($this->binA, 10);

        $result = $this->service()->complete([$order['order_id']], [[
            'item_id' => $this->variantId,
            'bins' => [['bin_id' => $this->binA, 'qty' => 3]],
        ]]);

        $this->assertSame(1, $result['completed_count']);
        $this->assertSame([], $result['blocked']);
        $this->assertSame(7, $this->onHand($this->binA));
        $this->assertSame('shipped', $this->orderStatus($order['order_id']));

        $allocation = OrderBinAllocation::where('order_id', $order['order_id'])->sole();
        $this->assertSame($this->binA, $allocation->bin_id);
        $this->assertSame(3, $allocation->qty);
        $this->assertNull($allocation->reversed_at);

        $this->assertDatabaseHas('inventory_movements', [
            'item_id' => $this->variantId,
            'bin_id' => $this->binA,
            'source' => 'ORDER_COMPLETE_OUT',
            'qty' => -3,
        ]);
    }

    public function test_membagi_pengambilan_ke_dua_rak(): void
    {
        $order = $this->seedOrder(qty: 5);
        $this->seedStock($this->binA, 2);
        $this->seedStock($this->binB, 8);

        $result = $this->service()->complete([$order['order_id']], [[
            'item_id' => $this->variantId,
            'bins' => [
                ['bin_id' => $this->binA, 'qty' => 2],
                ['bin_id' => $this->binB, 'qty' => 3],
            ],
        ]]);

        $this->assertSame(1, $result['completed_count']);
        $this->assertSame(0, $this->onHand($this->binA));
        $this->assertSame(5, $this->onHand($this->binB));
        $this->assertSame(2, OrderBinAllocation::where('order_id', $order['order_id'])->count());
    }

    public function test_menolak_saat_stok_gudang_kecil_kosong_dan_membuka_konfirmasi_pembeli(): void
    {
        $order = $this->seedOrder(qty: 2);

        $result = $this->service()->complete([$order['order_id']], []);

        $this->assertSame(0, $result['completed_count']);
        $this->assertSame(1, $result['raised_confirmations']);
        $this->assertSame(
            OrderDirectCompletionService::BLOCK_STOCK_SHORT,
            $result['blocked'][0]['reason'],
        );
        $this->assertSame('reserved', $this->orderStatus($order['order_id']));
        $this->assertSame(0, OrderBinAllocation::count());

        $confirmation = OrderBuyerConfirmation::where('order_id', $order['order_id'])->sole();
        $this->assertSame(2, $confirmation->qty_short);
        $this->assertNull($confirmation->outcome);
    }

    public function test_tidak_memotong_lebih_dari_stok_rak(): void
    {
        $order = $this->seedOrder(qty: 5);
        $this->seedStock($this->binA, 2);

        $result = $this->service()->complete([$order['order_id']], [[
            'item_id' => $this->variantId,
            'bins' => [['bin_id' => $this->binA, 'qty' => 5]],
        ]]);

        $this->assertSame(0, $result['completed_count']);
        $this->assertSame(2, $this->onHand($this->binA));
    }

    public function test_menolak_pesanan_yang_sudah_masuk_picklist(): void
    {
        $order = $this->seedOrder(qty: 1);
        $this->seedStock($this->binA, 10);
        $picklistNo = $this->attachPicklist($order['order_id'], $order['order_item_id']);

        $result = $this->service()->complete([$order['order_id']], [[
            'item_id' => $this->variantId,
            'bins' => [['bin_id' => $this->binA, 'qty' => 1]],
        ]]);

        $this->assertSame(0, $result['completed_count']);
        $this->assertSame(
            OrderDirectCompletionService::BLOCK_HAS_PICKLIST,
            $result['blocked'][0]['reason'],
        );
        $this->assertStringContainsString($picklistNo, $result['blocked'][0]['message']);
        $this->assertSame(10, $this->onHand($this->binA));
    }

    public function test_pembatalan_mengembalikan_stok_ke_rak_asal(): void
    {
        $order = $this->seedOrder(qty: 4);
        $this->seedStock($this->binA, 6);

        $this->service()->complete([$order['order_id']], [[
            'item_id' => $this->variantId,
            'bins' => [['bin_id' => $this->binA, 'qty' => 4]],
        ]]);

        $this->assertSame(2, $this->onHand($this->binA));

        app(SalesOrderService::class)->cancelLocally($order['order_id'], 'uji batal', $this->userId);

        $this->assertSame(6, $this->onHand($this->binA));
        $this->assertNotNull(
            OrderBinAllocation::where('order_id', $order['order_id'])->sole()->reversed_at,
        );
        $this->assertDatabaseHas('inventory_movements', [
            'item_id' => $this->variantId,
            'bin_id' => $this->binA,
            'source' => 'ORDER_COMPLETE_REVERSAL',
            'qty' => 4,
        ]);
    }

    public function test_keputusan_menunggu_tidak_menutup_konfirmasi_dan_tidak_menyentuh_channel(): void
    {
        $order = $this->seedOrder(qty: 2);
        $this->service()->complete([$order['order_id']], []);

        $confirmation = OrderBuyerConfirmation::where('order_id', $order['order_id'])->sole();

        app(BuyerConfirmationService::class)->decide(
            $confirmation->id,
            OrderBuyerConfirmation::OUTCOME_WAIT,
            null,
            'Pembeli bersedia menunggu',
        );

        $confirmation->refresh();
        $this->assertSame(OrderBuyerConfirmation::OUTCOME_WAIT, $confirmation->outcome);
        $this->assertNull($confirmation->resolved_at);

        Queue::assertNotPushed(SyncProductToChannelJob::class);
        Queue::assertNotPushed(CancelChannelOrderJob::class);
    }

    public function test_stok_masuk_melepas_pesanan_yang_menunggu(): void
    {
        $order = $this->seedOrder(qty: 2);
        $this->service()->complete([$order['order_id']], []);

        $confirmation = OrderBuyerConfirmation::where('order_id', $order['order_id'])->sole();

        app(BuyerConfirmationService::class)->decide(
            $confirmation->id,
            OrderBuyerConfirmation::OUTCOME_WAIT,
            null,
            null,
        );

        $this->seedStock($this->binA, 5);

        $released = app(BuyerConfirmationService::class)
            ->releaseWaitingForItems([$this->variantId], $this->locationId);

        $this->assertSame(1, $released);
        $this->assertNotNull($confirmation->refresh()->resolved_at);
    }

    public function test_pembatalan_lewat_konfirmasi_pembeli_tidak_menghubungi_marketplace(): void
    {
        $order = $this->seedOrder(qty: 2);
        $this->service()->complete([$order['order_id']], []);

        $confirmation = OrderBuyerConfirmation::where('order_id', $order['order_id'])->sole();

        app(BuyerConfirmationService::class)->decide(
            $confirmation->id,
            OrderBuyerConfirmation::OUTCOME_CANCEL,
            null,
            'Pembeli batal',
        );

        $this->assertSame('cancelled', $this->orderStatus($order['order_id']));
        $this->assertNotNull($confirmation->refresh()->resolved_at);

        Queue::assertNotPushed(SyncProductToChannelJob::class);
        Queue::assertNotPushed(CancelChannelOrderJob::class);
    }

    public function test_bulk_menyelesaikan_yang_bisa_dan_melaporkan_yang_tertahan(): void
    {
        $ready = $this->seedOrder(qty: 2);
        $held = $this->seedOrder(qty: 2);
        $this->attachPicklist($held['order_id'], $held['order_item_id']);
        $this->seedStock($this->binA, 10);

        $result = $this->service()->complete(
            [$ready['order_id'], $held['order_id']],
            [[
                'item_id' => $this->variantId,
                'bins' => [['bin_id' => $this->binA, 'qty' => 2]],
            ]],
        );

        $this->assertSame(1, $result['completed_count']);
        $this->assertCount(1, $result['blocked']);
        $this->assertSame($held['order_id'], $result['blocked'][0]['order_id']);
        $this->assertSame(8, $this->onHand($this->binA));
    }

    public function test_preview_melaporkan_kekurangan_stok(): void
    {
        $order = $this->seedOrder(qty: 5);
        $this->seedStock($this->binA, 2);

        $preview = $this->service()->preview([$order['order_id']]);

        $this->assertSame([], $preview['completable_order_ids']);
        $this->assertSame(3, $preview['items'][0]['shortage']);
        $this->assertSame(2, $preview['items'][0]['qty_available']);
    }

    private function onHand(string $binId): int
    {
        return (int) DB::table('inventories')
            ->where('item_id', $this->variantId)
            ->where('bin_id', $binId)
            ->value('on_hand');
    }

    private function orderStatus(string $orderId): string
    {
        return (string) DB::table('sales_orders')->where('id', $orderId)->value('status');
    }

    private function seedStock(string $binId, int $qty): void
    {
        $existing = DB::table('inventories')
            ->where('item_id', $this->variantId)
            ->where('location_id', $this->locationId)
            ->where('bin_id', $binId)
            ->value('id');

        if ($existing) {
            DB::table('inventories')->where('id', $existing)->update([
                'on_hand' => $qty,
                'available' => $qty,
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->variantId,
            'location_id' => $this->locationId,
            'bin_id' => $binId,
            'batch_no' => '',
            'serial_no' => '',
            'on_hand' => $qty,
            'on_order' => 0,
            'available' => $qty,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedUser(): string
    {
        $id = Str::uuid()->toString();
        DB::table('users')->insert([
            'id' => $id,
            'name' => 'Petugas',
            'email' => 'odc+'.substr($id, 0, 6).'@example.test',
            'password' => bcrypt('secret'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function seedLocation(string $code): string
    {
        $existing = DB::table('locations')->where('location_code', $code)->value('id');

        if ($existing) {
            return (string) $existing;
        }

        $id = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $id,
            'location_code' => $code,
            'location_name' => 'Gudang Kecil',
            'location_type' => 'WAREHOUSE',
            'is_warehouse' => true,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function seedBin(string $code): string
    {
        $id = Str::uuid()->toString();
        DB::table('location_bins')->insert([
            'id' => $id,
            'location_id' => $this->locationId,
            'bin_final_code' => $code,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function seedProductVariant(string $sku): string
    {
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Umum']);

        $productId = Str::uuid()->toString();
        DB::table('products')->insert([
            'id' => $productId,
            'name' => 'Produk '.$sku,
            'category_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $variantId = Str::uuid()->toString();
        DB::table('product_variants')->insert([
            'id' => $variantId,
            'product_id' => $productId,
            'sku' => $sku,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $variantId;
    }

    private function seedOrder(int $qty): array
    {
        $orderId = Str::uuid()->toString();
        DB::table('sales_orders')->insert([
            'id' => $orderId,
            'salesorder_no' => 'ODC-SO-'.substr($orderId, 0, 6),
            'customer_name' => 'Pembeli',
            'location_id' => $this->locationId,
            'status' => 'reserved',
            'transaction_date' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $orderItemId = Str::uuid()->toString();
        DB::table('sales_order_items')->insert([
            'id' => $orderItemId,
            'order_id' => $orderId,
            'item_id' => $this->variantId,
            'sku' => 'SKU-ODC-1',
            'qty_in_base' => $qty,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['order_id' => $orderId, 'order_item_id' => $orderItemId];
    }

    private function attachPicklist(string $orderId, string $orderItemId): string
    {
        $picklistId = Str::uuid()->toString();
        $picklistNo = 'ODC-PICK-'.substr($picklistId, 0, 6);

        DB::table('picklists')->insert([
            'id' => $picklistId,
            'picklist_no' => $picklistNo,
            'location_id' => $this->locationId,
            'picker_id' => $this->userId,
            'status' => Picklist::STATUS_IN_PROGRESS,
            'created_by' => $this->userId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('picklist_items')->insert([
            'id' => Str::uuid()->toString(),
            'picklist_id' => $picklistId,
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'item_id' => $this->variantId,
            'sku' => 'SKU-ODC-1',
            'qty_ordered' => 1,
            'qty_picked' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $picklistNo;
    }
}
