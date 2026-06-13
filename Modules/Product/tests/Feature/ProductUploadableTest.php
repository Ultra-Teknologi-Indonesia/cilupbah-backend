<?php

namespace Modules\Product\Tests\Feature;

use Tests\TestCase;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Channel\Models\ChannelShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

class ProductUploadableTest extends TestCase
{
    use RefreshDatabase;

    private ChannelShop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Cat']);

        $this->shop = ChannelShop::create([
            'shop_id' => '999',
            'access_token' => 'tok',
            'is_active' => true,
        ]);
    }

    private function makeProduct(string $name, string $status): Product
    {
        return Product::create([
            'name' => $name,
            'category_id' => 1,
            'status' => $status,
            'is_active' => true,
        ]);
    }

    public function test_uploadable_returns_only_unmapped_master_products()
    {
        $unmapped = $this->makeProduct('Master Unmapped', Product::STATUS_MASTER);

        $mapped = $this->makeProduct('Master Mapped', Product::STATUS_MASTER);
        ProductChannelMapping::create([
            'product_id' => $mapped->id,
            'channel_shop_id' => $this->shop->id,
            'external_product_id' => 'EXT-1',
            'sync_status' => 'synced',
        ]);

        $this->makeProduct('Download Item', Product::STATUS_DOWNLOAD);

        $response = $this->getJson("/api/v1/products/uploadable?channel=tiktok&shop_id={$this->shop->shop_id}");

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name')->all();

        $this->assertContains('Master Unmapped', $names);
        $this->assertNotContains('Master Mapped', $names);
        $this->assertNotContains('Download Item', $names);
        $this->assertCount(1, $names);
    }

    public function test_uploadable_requires_shop_id()
    {
        $response = $this->getJson('/api/v1/products/uploadable?channel=tiktok');
        $response->assertStatus(422);
    }

    public function test_uploadable_unknown_shop_returns_422()
    {
        $response = $this->getJson('/api/v1/products/uploadable?shop_id=does-not-exist');
        $response->assertStatus(422);
        $response->assertJsonPath('status', 'error');
    }
}
