<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\Attribute;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductMedia;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductVariantChannelMapping;
use Modules\Product\Models\ProductVariationType;
use Modules\Product\Models\VariantOption;
use Tests\TestCase;

class MasterFeedTest extends TestCase
{
    use RefreshDatabase;

    private Product $master;
    private Attribute $warna;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Casing']);
        DB::table('brands')->insertOrIgnore(['id' => 1, 'name' => 'Cilupbah']);

        $this->warna = Attribute::create(['name' => 'Warna', 'type' => 'sales']);

        $this->master = Product::create([
            'name' => 'Softcase Rhombic',
            'category_id' => 1,
            'brand_id' => 1,
            'status' => Product::STATUS_MASTER,
            'order_type' => 'PREORDER',
            'is_active' => true,
            'is_bundle' => false,
            'is_consignment' => true,
        ]);

        ProductVariationType::create([
            'product_id' => $this->master->id,
            'attribute_id' => $this->warna->id,
            'sort_order' => 0,
        ]);

        $merah = ProductVariant::create([
            'product_id' => $this->master->id,
            'sku' => 'RHOMBIC-MERAH',
            'sell_price' => 50000,
            'tax_rate' => 10,
            'is_active' => true,
            'is_internal' => false,
            'sequence_item' => 1,
        ]);
        $merah->barcode = '8991111111111';
        $merah->save();

        $hitam = ProductVariant::create([
            'product_id' => $this->master->id,
            'sku' => 'RHOMBIC-HITAM',
            'sell_price' => 75000,
            'tax_rate' => 11,
            'is_active' => true,
            'is_internal' => true,
            'sequence_item' => 2,
        ]);

        VariantOption::create(['variant_id' => $merah->id, 'attribute_id' => $this->warna->id, 'value' => 'Merah']);
        VariantOption::create(['variant_id' => $hitam->id, 'attribute_id' => $this->warna->id, 'value' => 'Hitam']);

        ProductMedia::create([
            'product_id' => $this->master->id,
            'media_type' => 'image',
            'url' => 'https://cdn.example.com/product.jpg',
            'is_primary' => true,
            'sort_order' => 1,
        ]);

        Product::create([
            'name' => 'Draft Item',
            'category_id' => 1,
            'status' => Product::STATUS_IN_REVIEW,
            'is_active' => true,
        ]);
    }

    private function seedChannelMapping(string $code, string $externalId, ?string $channelUrl): void
    {
        $channel = Channel::create(['code' => $code, 'name' => ucfirst($code), 'is_active' => true]);
        $shop = ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => "SHOP-{$code}",
            'shop_name' => "Toko {$code}",
            'is_active' => true,
        ]);

        $mapping = ProductChannelMapping::create([
            'product_id' => $this->master->id,
            'channel_shop_id' => $shop->id,
            'external_product_id' => $externalId,
            'channel_url' => $channelUrl,
            'sync_status' => 'synced',
        ]);

        $variant = $this->master->variants()->first();
        ProductVariantChannelMapping::create([
            'product_channel_mapping_id' => $mapping->id,
            'variant_id' => $variant->id,
            'external_sku_id' => 'SKU-1',
        ]);
    }

    public function test_list_returns_only_master_items_with_envelope(): void
    {
        $response = $this->getJson('/api/v1/products/master');

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');

        $names = collect($response->json('data'))->pluck('item_name')->all();
        $this->assertContains('Softcase Rhombic', $names);
        $this->assertNotContains('Draft Item', $names);
    }

    public function test_default_pagination_is_ten(): void
    {
        $response = $this->getJson('/api/v1/products/master');

        $response->assertStatus(200);
        $response->assertJsonPath('meta.per_page', 10);
    }

    public function test_item_structure_matches_example_master(): void
    {
        $response = $this->getJson('/api/v1/products/master');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                [
                    'item_group_id',
                    'is_po',
                    'item_name',
                    'last_modified',
                    'variations' => [['label', 'values']],
                    'sell_price',
                    'item_category_id',
                    'is_consignment',
                    'total_variants',
                    'online_status',
                    'thumbnail',
                    'variants' => [[
                        'item_group_id',
                        'item_id',
                        'item_code',
                        'item_name',
                        'is_bundle',
                        'is_consignment',
                        'variation_values' => [['label', 'value']],
                        'is_internal',
                        'barcode',
                        'tax_rate',
                        'thumbnail',
                        'store_names',
                        'sell_price',
                        'sequence_item',
                    ]],
                ],
            ],
        ]);

        $item = $response->json('data.0');
        $this->assertSame($this->master->id, $item['item_group_id']);
        $this->assertTrue($item['is_po']);
        $this->assertTrue($item['is_consignment']);
        $this->assertSame(2, $item['total_variants']);
        $this->assertEqualsCanonicalizing(['Merah', 'Hitam'], $item['variations'][0]['values']);
        $this->assertSame(50000, (int) $item['sell_price']);
        $this->assertSame('https://cdn.example.com/product.jpg', $item['thumbnail']);
    }

    public function test_variant_master_fields_are_exposed(): void
    {
        $response = $this->getJson('/api/v1/products/master');

        $variant = collect($response->json('data.0.variants'))
            ->firstWhere('item_code', 'RHOMBIC-MERAH');

        $this->assertSame($this->master->id, $variant['item_group_id']);
        $this->assertSame('8991111111111', $variant['barcode']);
        $this->assertSame(10, (int) $variant['tax_rate']);
        $this->assertFalse($variant['is_internal']);
        $this->assertSame(1, $variant['sequence_item']);
        $this->assertSame('Merah', $variant['variation_values'][0]['value']);
        $this->assertSame('Warna', $variant['variation_values'][0]['label']);
    }

    public function test_online_status_builds_channel_url_when_empty(): void
    {
        $this->seedChannelMapping('tiktok', 'TT123', null);

        $response = $this->getJson('/api/v1/products/master');

        $status = $response->json('data.0.online_status.0');
        $this->assertSame('tiktok', $status['channel_code']);
        $this->assertSame('TT123', $status['channel_group_id']);
        $this->assertSame('https://shop.tiktok.com/view/product/TT123', $status['channel_url']);
        $this->assertSame('Toko tiktok', $status['store_name']);

        $storeNames = collect($response->json('data.0.variants'))
            ->firstWhere('item_code', 'RHOMBIC-MERAH')['store_names'];
        $this->assertSame('Toko tiktok', $storeNames[0]['store_name']);
    }

    public function test_online_status_uses_stored_channel_url(): void
    {
        $this->seedChannelMapping('tokopedia', 'TKP9', 'https://tokopedia.com/cilupbah/softcase-TKP9');

        $response = $this->getJson('/api/v1/products/master');

        $status = $response->json('data.0.online_status.0');
        $this->assertSame('https://tokopedia.com/cilupbah/softcase-TKP9', $status['channel_url']);
    }

    public function test_search_filters_by_name(): void
    {
        $response = $this->getJson('/api/v1/products/master?search=Rhombic');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('item_name')->all();
        $this->assertContains('Softcase Rhombic', $names);
    }

    public function test_show_returns_single_master_item(): void
    {
        $response = $this->getJson("/api/v1/products/master/{$this->master->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('data.item_group_id', $this->master->id);
        $response->assertJsonPath('data.item_name', 'Softcase Rhombic');
    }

    public function test_show_non_master_returns_404(): void
    {
        $draftId = Product::where('status', Product::STATUS_IN_REVIEW)->value('id');

        $response = $this->getJson("/api/v1/products/master/{$draftId}");

        $response->assertStatus(404);
        $response->assertJsonPath('status', 'error');
    }

    public function test_list_rejects_invalid_status(): void
    {
        $response = $this->getJson('/api/v1/products/master?status=bogus');

        $response->assertStatus(422);
    }
}
