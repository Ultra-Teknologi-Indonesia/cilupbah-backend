<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Repositories\ChannelProductRepository;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Services\TikTokClient;
use Modules\Channel\Services\TikTokImageUploader;
use Modules\Channel\Services\TikTokProductMapper;
use Modules\Channel\Services\TikTokProductService;
use Modules\Product\Models\Attribute;
use Modules\Product\Models\Category;
use Modules\Product\Models\ChannelAttribute;
use Modules\Product\Models\ChannelAttributeOption;
use Modules\Product\Models\Product;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class BuildUploadConfigAttributeMappingTest extends TestCase
{
    use RefreshDatabase;

    private TikTokProductService $service;
    private string $channelCategoryId;
    private Product $product;
    private ChannelShop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $channel = Channel::create(['code' => 'tiktok', 'name' => 'TikTok']);

        $this->shop = ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => 'TK-TEST',
            'shop_name' => 'Test Store',
            'access_token' => 'tok',
            'is_active' => true,
        ]);

        $this->channelCategoryId = Uuid::uuid7()->toString();
        DB::table('channel_categories')->insert([
            'id' => $this->channelCategoryId,
            'channel_id' => $channel->id,
            'external_id' => '601226',
            'parent_external_id' => '0',
            'name' => 'Handphone',
            'is_leaf' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $category = Category::create(['name' => 'Handphone']);
        DB::table('category_channel_mappings')->insert([
            'category_id' => $category->id,
            'channel_category_id' => $this->channelCategoryId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->product = Product::create([
            'name' => 'Samsung Galaxy S25 Ultra Titanium Black 256GB',
            'description' => 'Flagship phone',
            'category_id' => $category->id,
            'is_active' => true,
        ]);

        $this->service = new TikTokProductService(
            $this->createMock(TikTokClient::class),
            new TikTokProductMapper(),
            new ChannelShopRepository(),
            new ChannelProductRepository(),
            $this->createMock(TikTokImageUploader::class),
        );
    }

    private function seedRequiredAttributes(): array
    {
        $garansi = ChannelAttribute::create([
            'channel_category_id' => $this->channelCategoryId,
            'external_id' => '100601',
            'name' => 'Jenis Garansi',
            'is_required' => true,
            'is_sale_prop' => false,
        ]);
        $garansiResmi = ChannelAttributeOption::create([
            'channel_attribute_id' => $garansi->id,
            'external_id' => '200901',
            'name' => 'Garansi Resmi',
        ]);
        $tidakBergaransi = ChannelAttributeOption::create([
            'channel_attribute_id' => $garansi->id,
            'external_id' => '200902',
            'name' => 'Tidak',
        ]);

        $dangerous = ChannelAttribute::create([
            'channel_category_id' => $this->channelCategoryId,
            'external_id' => '100602',
            'name' => 'Contains Dangerous Goods',
            'is_required' => true,
            'is_sale_prop' => false,
        ]);
        $no = ChannelAttributeOption::create([
            'channel_attribute_id' => $dangerous->id,
            'external_id' => '200903',
            'name' => 'No',
        ]);
        $yes = ChannelAttributeOption::create([
            'channel_attribute_id' => $dangerous->id,
            'external_id' => '200904',
            'name' => 'Yes',
        ]);

        return compact('garansi', 'garansiResmi', 'tidakBergaransi', 'dangerous', 'no', 'yes');
    }

    public function test_user_attribute_mapping_overrides_auto_inject_defaults(): void
    {
        $this->seedRequiredAttributes();

        $config = $this->service->buildUploadConfig($this->product, $this->shop, null, [
            '100601' => '200901',
            '100602' => '200903',
        ]);

        $productAttrs = collect($config['attributes']);

        $garansiAttr = $productAttrs->firstWhere('id', '100601');
        $this->assertNotNull($garansiAttr);
        $this->assertSame('200901', $garansiAttr['values'][0]['id']);
        $this->assertSame('Garansi Resmi', $garansiAttr['values'][0]['name']);

        $dangerousAttr = $productAttrs->firstWhere('id', '100602');
        $this->assertNotNull($dangerousAttr);
        $this->assertSame('200903', $dangerousAttr['values'][0]['id']);
        $this->assertSame('No', $dangerousAttr['values'][0]['name']);
    }

    public function test_user_mapping_includes_name_from_database(): void
    {
        $this->seedRequiredAttributes();

        $config = $this->service->buildUploadConfig($this->product, $this->shop, null, [
            '100601' => '200901',
        ]);

        $garansiAttr = collect($config['attributes'])->firstWhere('id', '100601');
        $this->assertArrayHasKey('name', $garansiAttr['values'][0]);
        $this->assertSame('Garansi Resmi', $garansiAttr['values'][0]['name']);
    }

    public function test_auto_inject_fills_uncovered_when_user_mapping_is_partial(): void
    {
        $this->seedRequiredAttributes();

        $config = $this->service->buildUploadConfig($this->product, $this->shop, null, [
            '100601' => '200901',
        ]);

        $productAttrs = collect($config['attributes']);

        $garansiAttr = $productAttrs->firstWhere('id', '100601');
        $this->assertSame('200901', $garansiAttr['values'][0]['id']);
        $this->assertSame('Garansi Resmi', $garansiAttr['values'][0]['name']);

        $dangerousAttr = $productAttrs->firstWhere('id', '100602');
        $this->assertNotNull($dangerousAttr, 'Auto-inject should fill uncovered required attr');
        $this->assertSame('200903', $dangerousAttr['values'][0]['id']);
        $this->assertSame('No', $dangerousAttr['values'][0]['name']);
    }

    public function test_auto_inject_fills_all_when_no_user_mapping(): void
    {
        $this->seedRequiredAttributes();

        $config = $this->service->buildUploadConfig($this->product, $this->shop);

        $productAttrs = collect($config['attributes']);
        $this->assertCount(2, $productAttrs);

        $garansiAttr = $productAttrs->firstWhere('id', '100601');
        $this->assertSame('200902', $garansiAttr['values'][0]['id'], 'Should pick "Tidak" as default');

        $dangerousAttr = $productAttrs->firstWhere('id', '100602');
        $this->assertSame('200903', $dangerousAttr['values'][0]['id'], 'Should pick "No" as default');
    }

    public function test_spec_mapped_attributes_take_priority_over_user_mapping(): void
    {
        $attrs = $this->seedRequiredAttributes();

        $localAttr = Attribute::create(['name' => 'Garansi', 'type' => 'spec']);
        DB::table('attribute_options')->insert([
            'id' => 1,
            'attribute_id' => $localAttr->id,
            'value' => 'Garansi Resmi',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('attribute_channel_mappings')->insert([
            'attribute_id' => $localAttr->id,
            'channel_attribute_id' => $attrs['garansi']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('attribute_option_channel_mappings')->insert([
            'attribute_option_id' => 1,
            'channel_attribute_option_id' => $attrs['garansiResmi']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('product_specifications')->insert([
            'product_id' => $this->product->id,
            'attribute_id' => $localAttr->id,
            'attribute_option_id' => 1,
            'text_value' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $config = $this->service->buildUploadConfig($this->product, $this->shop, null, [
            '100601' => '200902',
        ]);

        $productAttrs = collect($config['attributes']);
        $garansiAttr = $productAttrs->firstWhere('id', '100601');
        $this->assertSame('200901', $garansiAttr['values'][0]['id'],
            'Spec-mapped value (Garansi Resmi) should win over user mapping (Tidak Bergaransi)');
    }

    public function test_no_duplicate_attributes_across_layers(): void
    {
        $this->seedRequiredAttributes();

        $config = $this->service->buildUploadConfig($this->product, $this->shop, null, [
            '100601' => '200901',
            '100602' => '200904',
        ]);

        $productAttrs = collect($config['attributes']);
        $garansiEntries = $productAttrs->where('id', '100601');
        $this->assertCount(1, $garansiEntries, 'No duplicate attribute entries');

        $dangerousEntries = $productAttrs->where('id', '100602');
        $this->assertCount(1, $dangerousEntries, 'No duplicate attribute entries');
    }

    public function test_output_format_matches_tiktok_api(): void
    {
        $this->seedRequiredAttributes();

        $config = $this->service->buildUploadConfig($this->product, $this->shop, null, [
            '100601' => '200901',
        ]);

        foreach ($config['attributes'] as $attr) {
            $this->assertArrayHasKey('id', $attr, 'Each attribute must have id');
            $this->assertIsString($attr['id']);
            $this->assertArrayHasKey('values', $attr, 'Each attribute must have values');
            $this->assertIsArray($attr['values']);
            $this->assertNotEmpty($attr['values']);
            foreach ($attr['values'] as $val) {
                $this->assertArrayHasKey('id', $val, 'Each value must have id');
                $this->assertIsString($val['id']);
                $this->assertArrayHasKey('name', $val, 'Each value must have name for TikTok API');
            }
        }
    }

    public function test_category_id_resolved_in_config(): void
    {
        $this->seedRequiredAttributes();

        $config = $this->service->buildUploadConfig($this->product, $this->shop);

        $this->assertSame('601226', $config['category_id']);
    }
}
