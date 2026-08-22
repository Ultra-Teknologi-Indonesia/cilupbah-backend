<?php

namespace Modules\Product\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Channel\Jobs\SyncProductToChannelJob;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class ProductChannelUnlinkTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;
    private ProductVariant $variant;
    private ChannelShop $shop;
    private string $pcmId;
    private string $pvcmId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->createPrivilegedUser());

        $category = Category::create(['name' => 'Accessories']);
        $this->product = Product::create([
            'name' => 'Candy Case',
            'category_id' => $category->id,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
            'weight' => 0.2,
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'CANDY-IP-11',
            'sell_price' => 50000,
            'is_active' => true,
        ]);

        $channel = Channel::create(['code' => 'shopee', 'name' => 'Shopee']);
        $this->shop = ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => '407056477',
            'shop_name' => 'X-case id Official store',
            'is_active' => true,
        ]);

        $this->pcmId = Uuid::uuid7()->toString();
        DB::table('product_channel_mappings')->insert([
            'id' => $this->pcmId,
            'product_id' => $this->product->id,
            'channel_shop_id' => $this->shop->id,
            'external_product_id' => '53261826997',
            'sync_status' => 'synced',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->pvcmId = Uuid::uuid7()->toString();
        DB::table('product_variant_channel_mappings')->insert([
            'id' => $this->pvcmId,
            'product_channel_mapping_id' => $this->pcmId,
            'variant_id' => $this->variant->id,
            'external_sku_id' => 'EXT-SKU-11',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_unlink_product_channel_mapping_successfully(): void
    {
        $res = $this->deleteJson("/api/v1/products/{$this->product->id}/channel-mappings/{$this->pcmId}");

        $res->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('message', 'Tautan channel berhasil dihapus.');

        $this->assertDatabaseMissing('product_channel_mappings', ['id' => $this->pcmId]);
        $this->assertDatabaseMissing('product_variant_channel_mappings', ['id' => $this->pvcmId]);

        // Verify master product and variant remain intact
        $this->assertDatabaseHas('products', ['id' => $this->product->id]);
        $this->assertDatabaseHas('product_variants', ['id' => $this->variant->id]);
    }

    public function test_unlink_variant_channel_mapping_successfully(): void
    {
        $res = $this->deleteJson("/api/v1/products/{$this->product->id}/variant-channel-mappings/{$this->pvcmId}");

        $res->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseMissing('product_variant_channel_mappings', ['id' => $this->pvcmId]);
        // Parent mapping deleted if empty
        $this->assertDatabaseMissing('product_channel_mappings', ['id' => $this->pcmId]);
    }

    public function test_resync_channel_mapping_dispatches_job(): void
    {
        Queue::fake();

        $res = $this->postJson("/api/v1/products/{$this->product->id}/channel-mappings/{$this->pcmId}/re-sync");

        $res->assertOk()
            ->assertJsonPath('status', 'success');

        Queue::assertPushed(SyncProductToChannelJob::class, function ($job) {
            return $job->productId === $this->product->id && $job->channelShopId === $this->shop->id;
        });

        $this->assertDatabaseHas('product_channel_mappings', [
            'id' => $this->pcmId,
            'sync_status' => 'syncing',
        ]);
    }
}
