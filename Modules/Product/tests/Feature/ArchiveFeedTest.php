<?php

namespace Modules\Product\Tests\Feature;

use App\Models\User;
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

class ArchiveFeedTest extends TestCase
{
    use RefreshDatabase;

    private Product $archived;
    private User $archiver;
    private Attribute $warna;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Casing']);

        $this->warna = Attribute::create(['name' => 'Warna', 'type' => 'sales']);
        $this->archiver = User::factory()->create(['email' => 'archiver@cilupbah.id']);

        $this->archived = Product::create([
            'name' => 'Softcase Rhombic',
            'category_id' => 1,
            'status' => Product::STATUS_ARCHIVED,
            'order_type' => 'PREORDER',
            'is_active' => false,
            'is_bundle' => false,
            'is_consignment' => true,
            'archived_at' => '2026-06-05 10:00:00',
            'archived_by' => $this->archiver->id,
            'archive_reason' => 'discontinued',
        ]);

        ProductVariationType::create([
            'product_id' => $this->archived->id,
            'attribute_id' => $this->warna->id,
            'sort_order' => 0,
        ]);

        $merah = ProductVariant::create([
            'product_id' => $this->archived->id,
            'sku' => 'RHOMBIC-MERAH',
            'sell_price' => 50000,
            'tax_rate' => 10,
            'is_active' => true,
            'is_internal' => false,
            'sequence_item' => 1,
        ]);

        VariantOption::create(['variant_id' => $merah->id, 'attribute_id' => $this->warna->id, 'value' => 'Merah']);

        ProductMedia::create([
            'product_id' => $this->archived->id,
            'media_type' => 'image',
            'url' => 'https://cdn.example.com/archived.jpg',
            'is_primary' => true,
            'sort_order' => 1,
        ]);

        Product::create([
            'name' => 'Active Master',
            'category_id' => 1,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
        ]);
    }

    private function seedChannelMapping(string $code, string $externalId): void
    {
        $channel = Channel::create(['code' => $code, 'name' => ucfirst($code), 'is_active' => true]);
        $shop = ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => "SHOP-{$code}",
            'shop_name' => "Toko {$code}",
            'is_active' => true,
        ]);

        $mapping = ProductChannelMapping::create([
            'product_id' => $this->archived->id,
            'channel_shop_id' => $shop->id,
            'external_product_id' => $externalId,
            'sync_status' => 'synced',
        ]);

        $variant = $this->archived->variants()->first();
        ProductVariantChannelMapping::create([
            'product_channel_mapping_id' => $mapping->id,
            'variant_id' => $variant->id,
            'external_sku_id' => 'SKU-1',
        ]);
    }

    public function test_list_returns_only_archived_products_in_master_shape(): void
    {
        $this->seedChannelMapping('tiktok', 'TT123');

        $response = $this->getJson('/api/v1/products/archives');

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');

        $names = collect($response->json('data'))->pluck('item_name')->all();
        $this->assertContains('Softcase Rhombic', $names);
        $this->assertNotContains('Active Master', $names);

        $response->assertJsonStructure([
            'data' => [[
                'item_group_id',
                'item_name',
                'variations' => [['label', 'values']],
                'variants' => [['item_id', 'item_code', 'store_names']],
                'total_variants',
                'online_status',
                'thumbnail',
                'archived_at',
                'archived_by',
                'archive_reason',
            ]],
        ]);

        $item = $response->json('data.0');
        $this->assertSame($this->archived->id, $item['item_group_id']);
        $this->assertSame('archiver@cilupbah.id', $item['archived_by']);
        $this->assertSame('discontinued', $item['archive_reason']);
        $this->assertSame('tiktok', $item['online_status'][0]['channel_code']);
        $this->assertSame('TT123', $item['online_status'][0]['channel_group_id']);
    }

    public function test_default_pagination_is_ten(): void
    {
        $response = $this->getJson('/api/v1/products/archives');

        $response->assertStatus(200);
        $response->assertJsonPath('meta.per_page', 10);
    }

    public function test_search_filters_by_name(): void
    {
        $response = $this->getJson('/api/v1/products/archives?search=Rhombic');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('item_name')->all();
        $this->assertContains('Softcase Rhombic', $names);
    }

    public function test_filter_by_archived_by(): void
    {
        $other = User::factory()->create();
        Product::create([
            'name' => 'Other Archived',
            'category_id' => 1,
            'status' => Product::STATUS_ARCHIVED,
            'is_active' => false,
            'archived_at' => '2026-06-06 10:00:00',
            'archived_by' => $other->id,
        ]);

        $response = $this->getJson("/api/v1/products/archives?filter[archived_by]={$this->archiver->id}");

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('item_name')->all();
        $this->assertContains('Softcase Rhombic', $names);
        $this->assertNotContains('Other Archived', $names);
    }

    public function test_filter_by_archived_date_range(): void
    {
        Product::create([
            'name' => 'Old Archived',
            'category_id' => 1,
            'status' => Product::STATUS_ARCHIVED,
            'is_active' => false,
            'archived_at' => '2026-01-01 10:00:00',
            'archived_by' => $this->archiver->id,
        ]);

        $response = $this->getJson('/api/v1/products/archives?filter[archived_from]=2026-06-01&filter[archived_to]=2026-06-30');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('item_name')->all();
        $this->assertContains('Softcase Rhombic', $names);
        $this->assertNotContains('Old Archived', $names);
    }

    public function test_default_sort_is_archived_at_desc(): void
    {
        Product::create([
            'name' => 'Newest Archived',
            'category_id' => 1,
            'status' => Product::STATUS_ARCHIVED,
            'is_active' => false,
            'archived_at' => '2026-06-09 10:00:00',
            'archived_by' => $this->archiver->id,
        ]);

        $response = $this->getJson('/api/v1/products/archives');

        $response->assertStatus(200);
        $this->assertSame('Newest Archived', $response->json('data.0.item_name'));
    }

    public function test_show_returns_archived_product(): void
    {
        $response = $this->getJson("/api/v1/products/archives/{$this->archived->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.item_group_id', $this->archived->id);
        $response->assertJsonPath('data.item_name', 'Softcase Rhombic');
        $response->assertJsonPath('data.archive_reason', 'discontinued');
    }

    public function test_show_non_archived_returns_404(): void
    {
        $masterId = Product::where('status', Product::STATUS_MASTER)->value('id');

        $response = $this->getJson("/api/v1/products/archives/{$masterId}");

        $response->assertStatus(404);
        $response->assertJsonPath('status', 'error');
    }
}
