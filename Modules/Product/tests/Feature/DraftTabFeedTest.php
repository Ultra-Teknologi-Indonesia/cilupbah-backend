<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Channel\Jobs\SyncProductToChannelJob;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\Attribute;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelDraft;
use Modules\Product\Models\ProductMedia;
use Modules\Product\Models\ProductSyncLog;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\VariantOption;
use Tests\TestCase;

class DraftTabFeedTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;
    private Channel $channel;
    private ChannelShop $shopA;
    private ProductChannelDraft $draftA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Casing']);

        $this->product = Product::create([
            'name' => 'Draft Candy Ring',
            'category_id' => 1,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
        ]);
        $variantA = ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'DRF-A', 'sell_price' => 1000, 'is_active' => true]);
        $variantB = ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'DRF-B', 'sell_price' => 2000, 'is_active' => true]);

        $color = Attribute::create(['name' => 'Warna', 'type' => 'sales']);
        VariantOption::create(['variant_id' => $variantA->id, 'attribute_id' => $color->id, 'value' => 'Merah']);
        VariantOption::create(['variant_id' => $variantB->id, 'attribute_id' => $color->id, 'value' => 'Biru']);
        ProductMedia::create([
            'product_id' => $this->product->id,
            'media_type' => 'image',
            'url' => 'https://cdn.example.com/draft.jpg',
            'is_primary' => true,
            'sort_order' => 1,
        ]);

        $this->channel = Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);
        $this->shopA = ChannelShop::create([
            'channel_id' => $this->channel->id,
            'shop_id' => 'SHOP-A',
            'shop_name' => 'Cilupbah ID Mall',
            'is_active' => true,
        ]);

        $this->draftA = ProductChannelDraft::create([
            'product_id' => $this->product->id,
            'channel_shop_id' => $this->shopA->id,
            'channel_category_id' => 'CAT-1',
            'status' => ProductChannelDraft::STATUS_DRAFT,
        ]);
    }

    private function makeShop(string $shopId): ChannelShop
    {
        return ChannelShop::create([
            'channel_id' => $this->channel->id,
            'shop_id' => $shopId,
            'shop_name' => "Toko {$shopId}",
            'is_active' => true,
        ]);
    }

    public function test_list_returns_enriched_structure(): void
    {
        $response = $this->getJson('/api/v1/products/channel-drafts');

        $response->assertStatus(200);
        $response->assertJsonPath('meta.per_page', 10);
        $response->assertJsonStructure([
            'data' => [[
                'id',
                'item_group_id',
                'item_group_name',
                'thumbnail',
                'status',
                'can_upload',
                'max',
                'channel_code',
                'channel_name',
                'channel_id',
                'store_id',
                'active_store',
                'channel_category_id',
                'attribute_mapping',
                'price_override',
                'products' => [['item_name', 'item_code']],
            ]],
        ]);

        $entry = $response->json('data.0');
        $this->assertSame('Draft Candy Ring', $entry['item_group_name']);
        $this->assertSame('Cilupbah ID Mall', $entry['max']);
        $this->assertSame('shopee', $entry['channel_code']);
        $this->assertSame('https://cdn.example.com/draft.jpg', $entry['thumbnail']);
        $this->assertTrue($entry['can_upload']);
    }

    public function test_products_are_variants(): void
    {
        $entry = collect($this->getJson('/api/v1/products/channel-drafts')->json('data'))
            ->firstWhere('id', $this->draftA->id);

        $this->assertCount(2, $entry['products']);
        $this->assertEqualsCanonicalizing(['DRF-A', 'DRF-B'], collect($entry['products'])->pluck('item_code')->all());
    }

    public function test_cancelled_draft_cannot_upload_flag(): void
    {
        $cancelled = ProductChannelDraft::create([
            'product_id' => $this->product->id,
            'channel_shop_id' => $this->makeShop('SHOP-C')->id,
            'status' => ProductChannelDraft::STATUS_CANCELLED,
        ]);

        $data = collect($this->getJson('/api/v1/products/channel-drafts')->json('data'))->keyBy('id');

        $this->assertTrue($data[$this->draftA->id]['can_upload']);
        $this->assertFalse($data[$cancelled->id]['can_upload']);
    }

    public function test_search_by_product_name(): void
    {
        $response = $this->getJson('/api/v1/products/channel-drafts?search=Candy');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('item_group_name')->unique()->all();
        $this->assertContains('Draft Candy Ring', $names);
    }

    public function test_filter_by_status(): void
    {
        ProductChannelDraft::create([
            'product_id' => $this->product->id,
            'channel_shop_id' => $this->makeShop('SHOP-R')->id,
            'status' => ProductChannelDraft::STATUS_READY,
        ]);

        $response = $this->getJson('/api/v1/products/channel-drafts?filter[status]=ready');

        $response->assertStatus(200);
        $statuses = collect($response->json('data'))->pluck('status')->unique()->all();
        $this->assertSame(['ready'], $statuses);
    }

    public function test_upload_draft_moves_it_to_histories(): void
    {
        Queue::fake();

        $response = $this->postJson("/api/v1/products/channel-drafts/{$this->draftA->id}/upload");

        $response->assertStatus(200);
        Queue::assertPushed(SyncProductToChannelJob::class);

        $this->assertDatabaseMissing('product_channel_drafts', ['id' => $this->draftA->id]);
        $this->assertDatabaseHas('product_sync_logs', [
            'product_id' => $this->product->id,
            'channel_shop_id' => $this->shopA->id,
            'action' => ProductSyncLog::ACTION_UPLOAD,
            'status' => ProductSyncLog::STATUS_PENDING,
        ]);

        $history = $this->getJson('/api/v1/upload-histories');
        $entry = collect($history->json('data'))->firstWhere('item_group_id', $this->product->id);
        $this->assertNotNull($entry);
        $this->assertFalse($entry['success']);
        $this->assertSame('Sedang diproses', $entry['status_message']);
    }

    public function test_upload_cancelled_draft_returns_422(): void
    {
        $cancelled = ProductChannelDraft::create([
            'product_id' => $this->product->id,
            'channel_shop_id' => $this->makeShop('SHOP-X')->id,
            'status' => ProductChannelDraft::STATUS_CANCELLED,
        ]);

        $this->postJson("/api/v1/products/channel-drafts/{$cancelled->id}/upload")
            ->assertStatus(422);
    }

    public function test_upload_unknown_returns_404(): void
    {
        $this->postJson('/api/v1/products/channel-drafts/00000000-0000-0000-0000-000000000000/upload')
            ->assertStatus(404);
    }

    public function test_bulk_upload_reports_uploaded_and_skipped(): void
    {
        Queue::fake();

        $cancelled = ProductChannelDraft::create([
            'product_id' => $this->product->id,
            'channel_shop_id' => $this->makeShop('SHOP-B')->id,
            'status' => ProductChannelDraft::STATUS_CANCELLED,
        ]);

        $response = $this->postJson('/api/v1/products/channel-drafts/bulk-upload', [
            'ids' => [$this->draftA->id, $cancelled->id],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.uploaded', 1);
        $this->assertCount(1, $response->json('data.skipped'));
        $this->assertDatabaseMissing('product_channel_drafts', ['id' => $this->draftA->id]);
        $this->assertDatabaseHas('product_channel_drafts', ['id' => $cancelled->id]);
    }

    public function test_upload_blocked_when_multivariant_without_variation_attributes(): void
    {
        Queue::fake();

        $bare = Product::create([
            'name' => 'Bare Multivariant',
            'category_id' => 1,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
        ]);
        ProductVariant::create(['product_id' => $bare->id, 'sku' => 'BARE-A', 'sell_price' => 1000, 'is_active' => true]);
        ProductVariant::create(['product_id' => $bare->id, 'sku' => 'BARE-B', 'sell_price' => 2000, 'is_active' => true]);

        $draft = ProductChannelDraft::create([
            'product_id' => $bare->id,
            'channel_shop_id' => $this->shopA->id,
            'status' => ProductChannelDraft::STATUS_DRAFT,
        ]);

        $response = $this->postJson("/api/v1/products/channel-drafts/{$draft->id}/upload");

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'Produk multi-varian tanpa atribut variasi — wajib diisi sebelum upload ke shopee.',
        ]);

        Queue::assertNothingPushed();

        $this->assertDatabaseHas('product_channel_drafts', ['id' => $draft->id]);
        $this->assertDatabaseMissing('product_sync_logs', ['product_id' => $bare->id]);
    }
}
