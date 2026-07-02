<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Services\SalesReturnService;
use Tests\TestCase;

class SalesReturnFromChannelTest extends TestCase
{
    use RefreshDatabase;

    private function seedOrder(string $source, string $channelOrderNo): array
    {
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Kat CH', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $productId = Str::uuid()->toString();
        DB::table('products')->insert([
            'id' => $productId, 'category_id' => $categoryId, 'name' => 'Produk CH',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $variantId = Str::uuid()->toString();
        DB::table('product_variants')->insert([
            'id' => $variantId, 'product_id' => $productId, 'sku' => 'SKU-CH',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $locationId = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $locationId, 'location_code' => 'LOC-CH', 'location_name' => 'Gudang CH',
            'location_type' => 'WAREHOUSE', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $orderId = Str::uuid()->toString();
        DB::table('sales_orders')->insert([
            'id' => $orderId,
            'salesorder_no' => strtoupper($source) . '-' . $channelOrderNo,
            'channel_order_no' => $channelOrderNo,
            'customer_name' => 'Budi',
            'source' => $source,
            'location_id' => $locationId,
            'status' => 'shipped',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sales_order_items')->insert([
            'id' => Str::uuid()->toString(),
            'order_id' => $orderId,
            'item_id' => $variantId,
            'sku' => 'SKU-CH',
            'description' => 'Produk CH',
            'qty_in_base' => 3,
            'price' => 1000,
            'amount' => 3000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$orderId, $variantId, $locationId];
    }

    public function test_creates_marketplace_return_from_channel_event(): void
    {
        [$orderId, $variantId, $locationId] = $this->seedOrder('shopee', 'SP-123');

        $return = app(SalesReturnService::class)->createFromChannel([
            'source' => 'shopee',
            'channel_order_id' => 'SP-123',
            'channel_return_id' => 'RSN-1',
            'channel_shop_id' => 'shop-9',
            'created_by' => 'system:shopee-webhook',
        ]);

        $this->assertNotNull($return);
        $this->assertSame(SalesReturn::SOURCE_MARKETPLACE, $return->source);
        $this->assertSame(SalesReturn::STATUS_PENDING, $return->status);
        $this->assertSame($orderId, $return->order_id);
        $this->assertSame($locationId, $return->location_id);

        $this->assertDatabaseHas('sales_return_items', [
            'sales_return_id' => $return->id,
            'item_id' => $variantId,
            'qty' => 3,
        ]);
    }

    public function test_dedupes_on_channel_return_id(): void
    {
        $this->seedOrder('tiktok', 'TT-9');

        $svc = app(SalesReturnService::class);

        $first = $svc->createFromChannel([
            'source' => 'tiktok', 'channel_order_id' => 'TT-9',
            'channel_return_id' => 'RID-77', 'created_by' => 'system:tiktok-webhook',
        ]);
        $second = $svc->createFromChannel([
            'source' => 'tiktok', 'channel_order_id' => 'TT-9',
            'channel_return_id' => 'RID-77', 'created_by' => 'system:tiktok-webhook',
        ]);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(1, SalesReturn::where('source', SalesReturn::SOURCE_MARKETPLACE)->count());
    }

    public function test_skips_when_order_not_found(): void
    {
        $return = app(SalesReturnService::class)->createFromChannel([
            'source' => 'lazada', 'channel_order_id' => 'NOPE',
            'channel_return_id' => 'X', 'created_by' => 'system:lazada-webhook',
        ]);

        $this->assertNull($return);
    }
}
