<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ShopeeProductService;
use Modules\Product\Jobs\RaiseProductJob;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\RaiseProduct;
use Modules\Product\Models\RaiseProductDetail;
use Modules\Product\Services\RaiseProductService;
use Tests\TestCase;

class RaiseProductManageTest extends TestCase
{
    use RefreshDatabase;

    private Channel $shopee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Casing']);
        $this->shopee = Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);
    }

    private function makeShop(string $shopId): ChannelShop
    {
        return ChannelShop::create([
            'channel_id' => $this->shopee->id,
            'shop_id' => $shopId,
            'shop_name' => "Toko {$shopId}",
            'is_active' => true,
        ]);
    }

    private function makeMapping(ChannelShop $shop, string $ext): ProductChannelMapping
    {
        $product = Product::create(['name' => "Prod {$ext}", 'category_id' => 1, 'status' => 'master', 'is_active' => true]);
        ProductVariant::create(['product_id' => $product->id, 'sku' => 'SKU-' . uniqid(), 'sell_price' => 1000, 'is_active' => true]);

        return ProductChannelMapping::create([
            'product_id' => $product->id,
            'channel_shop_id' => $shop->id,
            'external_product_id' => $ext,
            'sync_status' => 'synced',
        ]);
    }

    public function test_create_succeeds_and_rejects_duplicate_store(): void
    {
        $shop = $this->makeShop('SHOP-C-1');

        $first = $this->postJson('/api/v1/raise-products', ['shop_id' => 'SHOP-C-1']);
        $first->assertStatus(201);
        $this->assertDatabaseHas('raise_products', ['channel_shop_id' => $shop->id]);

        $dup = $this->postJson('/api/v1/raise-products', ['shop_id' => 'SHOP-C-1']);
        $dup->assertStatus(422);
        $this->assertSame(1, RaiseProduct::where('channel_shop_id', $shop->id)->count());
    }

    public function test_create_unknown_store_returns_422(): void
    {
        $this->postJson('/api/v1/raise-products', ['shop_id' => 'does-not-exist'])->assertStatus(422);
    }

    public function test_add_product_succeeds_and_enforces_one_to_one(): void
    {
        $shop = $this->makeShop('SHOP-A-1');
        $raise = RaiseProduct::create(['channel_shop_id' => $shop->id, 'is_active' => true]);
        $mapping = $this->makeMapping($shop, 'EXT-A-1');

        $ok = $this->postJson("/api/v1/raise-products/{$raise->id}/products", [
            'product_channel_mapping_id' => $mapping->id,
        ]);
        $ok->assertStatus(201);
        $this->assertDatabaseHas('raise_product_details', [
            'raise_product_id' => $raise->id,
            'product_channel_mapping_id' => $mapping->id,
        ]);

        $dup = $this->postJson("/api/v1/raise-products/{$raise->id}/products", [
            'product_channel_mapping_id' => $mapping->id,
        ]);
        $dup->assertStatus(422);
    }

    public function test_add_product_rejects_mapping_from_other_store(): void
    {
        $shopA = $this->makeShop('SHOP-O-1');
        $shopB = $this->makeShop('SHOP-O-2');
        $raise = RaiseProduct::create(['channel_shop_id' => $shopA->id, 'is_active' => true]);
        $foreign = $this->makeMapping($shopB, 'EXT-O-2');

        $response = $this->postJson("/api/v1/raise-products/{$raise->id}/products", [
            'product_channel_mapping_id' => $foreign->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('raise_product_details', ['product_channel_mapping_id' => $foreign->id]);
    }

    public function test_add_product_unknown_raise_returns_404(): void
    {
        $shop = $this->makeShop('SHOP-U-1');
        $mapping = $this->makeMapping($shop, 'EXT-U-1');

        $this->postJson('/api/v1/raise-products/00000000-0000-0000-0000-000000000000/products', [
            'product_channel_mapping_id' => $mapping->id,
        ])->assertStatus(404);
    }

    public function test_remove_product(): void
    {
        $shop = $this->makeShop('SHOP-R-1');
        $raise = RaiseProduct::create(['channel_shop_id' => $shop->id, 'is_active' => true]);
        $mapping = $this->makeMapping($shop, 'EXT-R-1');
        $detail = RaiseProductDetail::create(['raise_product_id' => $raise->id, 'product_channel_mapping_id' => $mapping->id, 'is_active' => true]);

        $this->deleteJson("/api/v1/raise-products/{$raise->id}/products/{$detail->id}")->assertStatus(200);
        $this->assertDatabaseMissing('raise_product_details', ['id' => $detail->id]);
    }

    public function test_toggle_product_flags(): void
    {
        $shop = $this->makeShop('SHOP-T-1');
        $raise = RaiseProduct::create(['channel_shop_id' => $shop->id, 'is_active' => true]);
        $mapping = $this->makeMapping($shop, 'EXT-T-1');
        $detail = RaiseProductDetail::create(['raise_product_id' => $raise->id, 'product_channel_mapping_id' => $mapping->id, 'is_active' => true, 'is_repeatable' => false]);

        $response = $this->patchJson("/api/v1/raise-products/{$raise->id}/products/{$detail->id}", [
            'is_active' => false,
            'is_repeatable' => true,
        ]);

        $response->assertStatus(200);
        $detail->refresh();
        $this->assertFalse($detail->is_active);
        $this->assertTrue($detail->is_repeatable);
    }

    public function test_raise_queues_job_and_sets_start_time(): void
    {
        Queue::fake();

        $shop = $this->makeShop('SHOP-Q-1');
        $raise = RaiseProduct::create(['channel_shop_id' => $shop->id, 'is_active' => true]);
        $mapping = $this->makeMapping($shop, 'EXT-Q-1');
        $detail = RaiseProductDetail::create(['raise_product_id' => $raise->id, 'product_channel_mapping_id' => $mapping->id, 'is_active' => true]);

        $response = $this->postJson("/api/v1/raise-products/{$raise->id}/raise");

        $response->assertStatus(202);
        $detail->refresh();
        $this->assertNotNull($detail->start_time);
        $this->assertNull($detail->is_success);
        Queue::assertPushed(RaiseProductJob::class);
    }

    public function test_execute_raise_marks_success(): void
    {
        $shop = $this->makeShop('SHOP-E-1');
        $raise = RaiseProduct::create(['channel_shop_id' => $shop->id, 'is_active' => true]);
        $mapping = $this->makeMapping($shop, 'EXT-E-1');
        $detail = RaiseProductDetail::create(['raise_product_id' => $raise->id, 'product_channel_mapping_id' => $mapping->id, 'is_active' => true]);

        $this->mock(ShopeeProductService::class, function ($mock) {
            $mock->shouldReceive('boostItem')
                ->once()
                ->andReturn(['EXT-E-1' => ['success' => true, 'reason' => null]]);
        });

        (new RaiseProductJob($raise->id, null))->handle(app(RaiseProductService::class));

        $detail->refresh();
        $this->assertTrue($detail->is_success);
        $this->assertNotNull($detail->end_time);
        $this->assertStringContainsString('Shopee', $detail->reason);
    }

    public function test_delete_header_cascades_details(): void
    {
        $shop = $this->makeShop('SHOP-D-1');
        $raise = RaiseProduct::create(['channel_shop_id' => $shop->id, 'is_active' => true]);
        $mapping = $this->makeMapping($shop, 'EXT-D-1');
        $detail = RaiseProductDetail::create(['raise_product_id' => $raise->id, 'product_channel_mapping_id' => $mapping->id, 'is_active' => true]);

        $this->deleteJson("/api/v1/raise-products/{$raise->id}")->assertStatus(200);

        $this->assertDatabaseMissing('raise_products', ['id' => $raise->id]);
        $this->assertDatabaseMissing('raise_product_details', ['id' => $detail->id]);
    }
}
