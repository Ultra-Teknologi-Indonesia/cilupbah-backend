<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ShopeeProductService;
use Modules\Product\Models\ChannelCategory;
use Tests\TestCase;

class ShopeeCategorySyncTest extends TestCase
{
    use RefreshDatabase;

    private Channel $shopee;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.shopee.partner_id' => '200123',
            'services.shopee.partner_key' => 'test_partner_key',
            'services.shopee.host' => 'https://partner.shopeemobile.com',
        ]);

        $this->shopee = Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);
        ChannelShop::create([
            'channel_id' => $this->shopee->id,
            'shop_id' => '778899',
            'shop_name' => 'Shopee 778899',
            'access_token' => 'valid-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHours(4),
            'is_active' => true,
        ]);
    }

    public function test_sync_category_tree_persists_categories(): void
    {
        Http::fake([
            'partner.shopeemobile.com/api/v2/product/get_category*' => Http::response([
                'response' => ['category_list' => [
                    ['category_id' => 100001, 'parent_category_id' => 0, 'original_category_name' => 'Fashion', 'has_children' => true],
                    ['category_id' => 100002, 'parent_category_id' => 100001, 'original_category_name' => 'Kaos', 'has_children' => false],
                ]],
            ], 200),
        ]);

        $count = app(ShopeeProductService::class)->syncCategoryTree('778899');

        $this->assertEquals(2, $count);

        $leaf = ChannelCategory::where('channel_id', $this->shopee->id)->where('external_id', '100002')->first();
        $this->assertNotNull($leaf);
        $this->assertEquals('Kaos', $leaf->name);
        $this->assertTrue($leaf->is_leaf);
        $this->assertEquals('100001', $leaf->parent_external_id);

        $parent = ChannelCategory::where('external_id', '100001')->first();
        $this->assertFalse($parent->is_leaf);
    }

    public function test_sync_category_attributes_persists_attributes_and_options(): void
    {
        $category = ChannelCategory::create([
            'channel_id' => $this->shopee->id,
            'external_id' => '100002',
            'parent_external_id' => '100001',
            'name' => 'Kaos',
            'is_leaf' => true,
        ]);

        Http::fake([
            'partner.shopeemobile.com/api/v2/product/get_attributes*' => Http::response([
                'response' => ['attribute_list' => [[
                    'attribute_id' => 9001,
                    'original_attribute_name' => 'Bahan',
                    'is_mandatory' => true,
                    'input_validation_type' => 'DROP_DOWN',
                    'attribute_value_list' => [
                        ['value_id' => 5001, 'original_value_name' => 'Katun'],
                        ['value_id' => 5002, 'original_value_name' => 'Polyester'],
                    ],
                ]]],
            ], 200),
        ]);

        $count = app(ShopeeProductService::class)->syncCategoryAttributes('778899', '100002');

        $this->assertEquals(1, $count);

        $attr = DB::table('channel_attributes')->where('channel_category_id', $category->id)->where('external_id', '9001')->first();
        $this->assertNotNull($attr);
        $this->assertEquals('Bahan', $attr->name);
        $this->assertTrue((bool) $attr->is_required);

        $this->assertEquals(2, DB::table('channel_attribute_options')->where('channel_attribute_id', $attr->id)->count());
    }

    public function test_sync_category_attributes_requires_synced_category(): void
    {
        Http::fake();

        $this->expectException(\Exception::class);

        app(ShopeeProductService::class)->syncCategoryAttributes('778899', '999999');
    }

    public function test_get_model_list_returns_tier_variation_and_models(): void
    {
        Http::fake([
            'partner.shopeemobile.com/api/v2/product/get_model_list*' => Http::response([
                'response' => [
                    'tier_variation' => [['name' => 'Warna', 'option_list' => [['option' => 'Merah'], ['option' => 'Biru']]]],
                    'model' => [
                        ['model_id' => 700001, 'model_sku' => 'SKU-RED', 'tier_index' => [0]],
                        ['model_id' => 700002, 'model_sku' => 'SKU-BLUE', 'tier_index' => [1]],
                    ],
                ],
            ], 200),
        ]);

        $result = app(ShopeeProductService::class)->getModelList('778899', '555001');

        $this->assertCount(1, $result['tier_variation']);
        $this->assertCount(2, $result['models']);
        $this->assertEquals('SKU-RED', $result['models'][0]['model_sku']);
    }
}
