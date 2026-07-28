<?php

namespace Modules\Channel\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Channel\Jobs\SyncProductToChannelJob;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\LazadaProductMapper;
use Modules\Product\Models\Attribute;
use Modules\Product\Models\Category;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class ProductVariantChannelPropagationTest extends TestCase
{
    use RefreshDatabase;

    public function test_outbound_mapper_excludes_superseded_variants(): void
    {
        $out = app(LazadaProductMapper::class)->map([
            'name' => 'P', 'category_id' => null,
            'variants' => [
                ['sku' => 'IP17-BLUE-256', 'sell_price' => 100, 'is_active' => true],
                ['sku' => 'IP17-BLUE', 'sell_price' => 100, 'is_active' => false], 
            ],
        ]);

        $skus = $out['Request']['Product']['Skus']['Sku'];
        $this->assertCount(1, $skus);
        $this->assertSame('IP17-BLUE-256', $skus[0]['SellerSku']);
    }

    public function test_variant_expansion_dispatches_channel_update(): void
    {
        $user = $this->createPrivilegedUser();
        $this->actingAs($user);
        config(['channel.auto_push_product_content' => true]);

        $category = Category::create(['name' => 'Handphone']);
        $warna = Attribute::firstOrCreate(['name' => 'Warna'], ['type' => 'sales']);
        $ukuran = Attribute::firstOrCreate(['name' => 'Ukuran'], ['type' => 'sales']);

        $channel = Channel::create(['code' => 'lazada', 'name' => 'Lazada']);
        $shop = ChannelShop::create([
            'channel_id' => $channel->id, 'shop_id' => 'LZ1', 'shop_name' => 'Toko',
            'access_token' => 'tok', 'is_active' => true,
        ]);

        $id = $this->postJson('/api/v1/products', [
            'name' => 'iPhone 17', 'sku' => 'IP17', 'category_id' => $category->id,
            'media' => [['url' => 'https://img.test/a.jpg', 'media_type' => 'image']],
            'variation_types' => [['attribute_id' => $warna->id, 'sort_order' => 0]],
            'variants' => [
                ['sku' => 'IP17-BLUE', 'sell_price' => 9000, 'is_active' => true,
                    'options' => [['attribute_id' => $warna->id, 'value' => 'Blue']]],
            ],
        ])->assertCreated()->json('data.product_id');

        DB::table('product_channel_mappings')->insert([
            'id' => Uuid::uuid7()->toString(),
            'product_id' => $id, 'channel_shop_id' => $shop->id,
            'sync_status' => 'synced', 'external_product_id' => 'IT-1',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Queue::fake();

        $this->putJson("/api/v1/products/{$id}", [
            'variation_types' => [['attribute_id' => $warna->id, 'sort_order' => 0], ['attribute_id' => $ukuran->id, 'sort_order' => 1]],
            'variants' => [
                ['sku' => 'IP17-BLUE-256', 'sell_price' => 20000, 'is_active' => true,
                    'options' => [['attribute_id' => $warna->id, 'value' => 'Blue'], ['attribute_id' => $ukuran->id, 'value' => '256/8']]],
            ],
        ])->assertOk();

        Queue::assertPushed(SyncProductToChannelJob::class, fn ($job) =>
            $job->action === 'update' && $job->channelShopId === $shop->id && $job->productId === $id);
    }

    public function test_no_dispatch_when_no_channel_connected(): void
    {
        $user = $this->createPrivilegedUser();
        $this->actingAs($user);

        $category = Category::create(['name' => 'HP']);
        $warna = Attribute::firstOrCreate(['name' => 'Warna'], ['type' => 'sales']);
        $ukuran = Attribute::firstOrCreate(['name' => 'Ukuran'], ['type' => 'sales']);

        $id = $this->postJson('/api/v1/products', [
            'name' => 'P', 'sku' => 'P1', 'category_id' => $category->id,
            'media' => [['url' => 'https://img.test/a.jpg', 'media_type' => 'image']],
            'variation_types' => [['attribute_id' => $warna->id, 'sort_order' => 0]],
            'variants' => [['sku' => 'P1-BLUE', 'sell_price' => 100, 'is_active' => true,
                'options' => [['attribute_id' => $warna->id, 'value' => 'Blue']]]],
        ])->assertCreated()->json('data.product_id');

        Queue::fake();

        $this->putJson("/api/v1/products/{$id}", [
            'variation_types' => [['attribute_id' => $warna->id, 'sort_order' => 0], ['attribute_id' => $ukuran->id, 'sort_order' => 1]],
            'variants' => [['sku' => 'P1-BLUE-256', 'sell_price' => 200, 'is_active' => true,
                'options' => [['attribute_id' => $warna->id, 'value' => 'Blue'], ['attribute_id' => $ukuran->id, 'value' => '256']]]],
        ])->assertOk();

        Queue::assertNothingPushed();
    }
}
