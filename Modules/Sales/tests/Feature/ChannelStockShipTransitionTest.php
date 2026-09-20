<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Exceptions\ChannelOrderBeforeIntakeCutoffException;
use Modules\Sales\Services\SalesOrderService;
use Tests\TestCase;

class ChannelStockShipTransitionTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $service;
    protected string $variantId;
    protected string $locationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SalesOrderService::class);

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Kategori Stok',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = Str::uuid()->toString();
        DB::table('products')->insert([
            'id' => $productId,
            'category_id' => $categoryId,
            'name' => 'Produk Stok',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->variantId = Str::uuid()->toString();
        DB::table('product_variants')->insert([
            'id' => $this->variantId,
            'product_id' => $productId,
            'sku' => 'SKU-SHIP',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $channelId = Str::uuid()->toString();
        DB::table('channels')->insert([
            'id' => $channelId,
            'name' => 'Lazada',
            'code' => 'lazada',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $shopUuid = Str::uuid()->toString();
        DB::table('channel_shops')->insert([
            'id' => $shopUuid,
            'channel_id' => $channelId,
            'shop_id' => 'SHOP-1',
            'shop_name' => 'Lazada Shop 1',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pcmId = Str::uuid()->toString();
        DB::table('product_channel_mappings')->insert([
            'id' => $pcmId,
            'channel_shop_id' => $shopUuid,
            'product_id' => $productId,
            'external_product_id' => 'EXT-P-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('product_variant_channel_mappings')->insert([
            'id' => Str::uuid()->toString(),
            'product_channel_mapping_id' => $pcmId,
            'variant_id' => $this->variantId,
            'external_sku_id' => 'SKU-SHIP',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $kecilCode = \Modules\Warehouse\Models\Location::SYSTEM_KECIL_CODE;
        $existing = DB::table('locations')->where('location_code', $kecilCode)->value('id');

        if ($existing) {
            $this->locationId = $existing;
        } else {
            $this->locationId = Str::uuid()->toString();
            DB::table('locations')->insert([
                'id' => $this->locationId,
                'location_code' => $kecilCode,
                'location_name' => 'Gudang Kecil',
                'location_type' => 'WAREHOUSE',
                'is_warehouse' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->variantId,
            'location_id' => $this->locationId,
            'bin_id' => null,
            'on_hand' => 10,
            'on_order' => 0,
            'available' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function orderData(string $orderNo, string $channelStatus): array
    {
        return [
            'salesorder_no' => $orderNo,
            'channel_shop_id' => 'SHOP-1',
            'customer_name' => 'Buyer Stok',
            'transaction_date' => now(),
            'sub_total' => 10000,
            'total_disc' => 0,
            'total_tax' => 0,
            'shipping_cost' => 0,
            'insurance_cost' => 0,
            'grand_total' => 10000,
            'shipping_full_name' => null,
            'shipping_phone' => null,
            'shipping_address' => null,
            'shipping_city' => null,
            'shipping_province' => null,
            'shipping_post_code' => null,
            'shipping_country' => null,
            'channel_status' => $channelStatus,
            'status' => 'pending',
            'is_paid' => $channelStatus !== 'UNPAID',
            'payment_method' => null,
            'source' => 'lazada',
            'items' => [[
                'channel_product_id' => 'CP-1',
                'sku' => 'SKU-SHIP',
                'description' => 'Item Ship',
                'qty_in_base' => 2,
                'price' => 5000,
                'disc' => 0,
                'disc_amount' => 0,
                'tax_amount' => 0,
                'amount' => 10000,
            ]],
        ];
    }

    protected function inventory(): object
    {
        return DB::table('inventories')
            ->where('item_id', $this->variantId)
            ->where('location_id', $this->locationId)
            ->first();
    }

    protected function movements(string $source): int
    {
        return DB::table('inventory_movements')
            ->where('item_id', $this->variantId)
            ->where('source', $source)
            ->count();
    }

    public function test_reserved_to_shipped_releases_allocation_without_touching_on_hand(): void
    {
        $this->service->upsertFromChannel($this->orderData('LZ-SHIP-1', 'AWAITING_SHIPMENT'));

        $inv = $this->inventory();
        $this->assertSame(2, $inv->on_order);
        $this->assertSame(10, $inv->on_hand);

        $this->service->upsertFromChannel($this->orderData('LZ-SHIP-1', 'DELIVERED'));

        $inv = $this->inventory();
        $this->assertSame(0, $inv->on_order, 'alokasi harus dilepas saat shipped tanpa picklist WMS');
        $this->assertSame(
            10,
            $inv->on_hand,
            'jalur channel TIDAK memotong fisik: sejak 647876d1 hanya PicklistService::pickItem '
            . 'yang menurunkan on_hand, di rak yang benar-benar di-scan'
        );
        $this->assertSame(1, $this->movements('ORDER_RELEASE'));
        $this->assertSame(0, $this->movements('ORDER_PICK'));
        $this->assertSame(0, $this->movements('ORDER_SHIP'));

        $this->assertDatabaseHas('sales_orders', [
            'salesorder_no' => 'LZ-SHIP-1',
            'status' => 'shipped',
        ]);
    }

    public function test_packed_to_shipped_does_not_double_decrement(): void
    {
        $this->service->upsertFromChannel($this->orderData('LZ-SHIP-2', 'AWAITING_SHIPMENT'));

        DB::table('sales_orders')->where('salesorder_no', 'LZ-SHIP-2')->update(['status' => 'packed']);
        DB::table('inventories')
            ->where('item_id', $this->variantId)
            ->update(['on_hand' => 8, 'on_order' => 0, 'available' => 8]);

        $this->service->upsertFromChannel($this->orderData('LZ-SHIP-2', 'DELIVERED'));

        $inv = $this->inventory();
        $this->assertSame(8, $inv->on_hand, 'on_hand tidak boleh dikurangi dua kali');
        $this->assertSame(0, $inv->on_order);
        $this->assertSame(0, $this->movements('ORDER_PICK'), 'tidak boleh ada PICK kedua dari jalur channel');
        $this->assertSame(0, $this->movements('ORDER_SHIP'));
    }

    public function test_first_pull_already_delivered_leaves_stock_untouched(): void
    {
        $this->service->upsertFromChannel($this->orderData('LZ-SHIP-3', 'DELIVERED'));

        $inv = $this->inventory();
        $this->assertSame(10, $inv->on_hand);
        $this->assertSame(0, $inv->on_order);
        $this->assertSame(0, $this->movements('ORDER_PICK'));
        $this->assertSame(0, $this->movements('ORDER_SHIP'));
    }

    public function test_new_channel_order_before_intake_cutoff_is_not_created(): void
    {
        config(['queue.channel_order_intake.cutoff_at' => '2026-09-16T16:00:00+07:00']);

        $data = $this->orderData('LZ-CUTOFF-BEFORE', 'AWAITING_SHIPMENT');
        $data['transaction_date'] = '2026-09-16 08:59:59'; 

        try {
            $this->service->upsertFromChannel($data);
            $this->fail('Order sebelum cutoff tidak boleh dibuat.');
        } catch (ChannelOrderBeforeIntakeCutoffException) {
            $this->assertDatabaseMissing('sales_orders', [
                'salesorder_no' => 'LZ-CUTOFF-BEFORE',
            ]);
            $this->assertSame(10, $this->inventory()->on_hand);
            $this->assertSame(0, $this->inventory()->on_order);
        }
    }

    public function test_new_channel_order_at_intake_cutoff_is_created(): void
    {
        config(['queue.channel_order_intake.cutoff_at' => '2026-09-16T16:00:00+07:00']);

        $data = $this->orderData('LZ-CUTOFF-AT', 'AWAITING_SHIPMENT');
        $data['transaction_date'] = '2026-09-16 09:00:00'; 

        $this->service->upsertFromChannel($data);

        $this->assertDatabaseHas('sales_orders', [
            'salesorder_no' => 'LZ-CUTOFF-AT',
        ]);
    }

    public function test_new_channel_order_without_verified_transaction_date_is_not_created(): void
    {
        config(['queue.channel_order_intake.cutoff_at' => '2026-09-16T16:00:00+07:00']);

        $data = $this->orderData('LZ-CUTOFF-UNKNOWN', 'AWAITING_SHIPMENT');
        $data['transaction_date'] = now();
        $data['_channel_transaction_date_verified'] = false;

        $this->expectException(ChannelOrderBeforeIntakeCutoffException::class);

        $this->service->upsertFromChannel($data);
    }

    public function test_existing_channel_order_before_intake_cutoff_still_receives_cancel_update(): void
    {
        config(['queue.channel_order_intake.cutoff_at' => '']);

        $data = $this->orderData('LZ-CUTOFF-EXISTING', 'AWAITING_SHIPMENT');
        $data['transaction_date'] = '2026-09-16 08:00:00';
        $orderId = $this->service->upsertFromChannel($data);

        config(['queue.channel_order_intake.cutoff_at' => '2026-09-16T16:00:00+07:00']);
        $data = $this->orderData('LZ-CUTOFF-EXISTING', 'CANCELLED');
        $data['transaction_date'] = '2026-09-16 08:00:00';

        $this->assertSame($orderId, $this->service->upsertFromChannel($data));
        $this->assertDatabaseHas('sales_orders', [
            'id' => $orderId,
            'status' => 'cancelled',
            'is_canceled' => true,
        ]);
    }

    public function test_first_channel_snapshot_records_its_current_channel_status_without_fake_packing_history(): void
    {
        $this->service->upsertFromChannel($this->orderData('LZ-SHIP-SNAPSHOT', 'IN_TRANSIT'));

        $orderId = DB::table('sales_orders')
            ->where('salesorder_no', 'LZ-SHIP-SNAPSHOT')
            ->value('id');

        $snapshot = DB::table('sales_order_status_histories')
            ->where('salesorder_id', $orderId)
            ->where('action', 'CHANNEL_STATUS')
            ->sole();

        $metadata = json_decode((string) $snapshot->metadata, true);

        $this->assertSame('SHIPPED', $metadata['new_values']['channel_status']);
        $this->assertSame('IN_TRANSIT', $metadata['new_values']['channel_status_raw']);
        $this->assertSame('channel_initial_snapshot', $metadata['origin']);
        $this->assertSame(0, DB::table('sales_order_status_histories')
            ->where('salesorder_id', $orderId)
            ->whereIn('action', ['FINISH_PICK', 'FINISH_PACK'])
            ->count());
    }

    public function test_terminal_channel_status_sets_received_date_without_observer(): void
    {
        foreach (['TO_CONFIRM_RECEIVE', 'COMPLETED'] as $index => $channelStatus) {
            $data = $this->orderData('LZ-RECEIVED-'.$index, $channelStatus);
            $receivedAt = now()->subMinutes($index + 1);
            $data['channel_updated_at'] = $receivedAt;

            $this->service->upsertFromChannel($data);

            $order = DB::table('sales_orders')
                ->where('salesorder_no', 'LZ-RECEIVED-'.$index)
                ->first(['status', 'channel_status', 'received_date']);

            $this->assertSame('shipped', $order->status);
            $this->assertSame($channelStatus, $order->channel_status);
            $this->assertNotNull($order->received_date);
            $this->assertEquals($receivedAt->format('Y-m-d H:i:s'), $order->received_date);
        }
    }

    public function test_reserved_to_cancelled_still_releases_reserved(): void
    {
        $this->service->upsertFromChannel($this->orderData('LZ-SHIP-4', 'AWAITING_SHIPMENT'));
        $this->assertSame(2, $this->inventory()->on_order);

        $this->service->upsertFromChannel($this->orderData('LZ-SHIP-4', 'CANCELLED'));

        $inv = $this->inventory();
        $this->assertSame(0, $inv->on_order);
        $this->assertSame(10, $inv->on_hand);

        $this->assertSame(1, $this->movements('ORDER_RELEASE'));
    }

    public function test_shipped_order_is_not_reprocessed_on_repeat_webhook(): void
    {
        $this->service->upsertFromChannel($this->orderData('LZ-SHIP-5', 'AWAITING_SHIPMENT'));
        $this->service->upsertFromChannel($this->orderData('LZ-SHIP-5', 'DELIVERED'));
        $this->service->upsertFromChannel($this->orderData('LZ-SHIP-5', 'COMPLETED'));

        $inv = $this->inventory();
        $this->assertSame(10, $inv->on_hand, 'webhook berulang tidak boleh menyentuh stok fisik');
        $this->assertSame(0, $inv->on_order);
        $this->assertSame(
            1,
            $this->movements('ORDER_RELEASE'),
            'alokasi dilepas tepat sekali walau webhook datang berkali-kali'
        );
        $this->assertSame(0, $this->movements('ORDER_PICK'));
        $this->assertSame(0, $this->movements('ORDER_SHIP'));
    }
}
