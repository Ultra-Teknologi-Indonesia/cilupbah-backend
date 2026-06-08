<?php

namespace Modules\Product\Tests\Feature;

use Tests\TestCase;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelDraft;
use Modules\Channel\Models\ChannelShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

class ProductDraftTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;
    private ChannelShop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Cat']);

        $this->shop = ChannelShop::create([
            'shop_id' => '777',
            'access_token' => 'tok',
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'name' => 'Draftable Product',
            'category_id' => 1,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
        ]);
    }

    public function test_store_creates_draft()
    {
        $response = $this->postJson("/api/v1/products/{$this->product->id}/channel-drafts", [
            'shop_id' => $this->shop->shop_id,
            'channel_category_id' => 'CAT-1',
            'attribute_mapping' => ['color' => 'attr-1'],
            'price_override' => 12345,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.channel_category_id', 'CAT-1');

        $this->assertDatabaseHas('product_channel_drafts', [
            'product_id' => $this->product->id,
            'channel_shop_id' => $this->shop->id,
            'channel_category_id' => 'CAT-1',
        ]);
    }

    public function test_store_is_upsert_per_product_and_shop()
    {
        $payload = ['shop_id' => $this->shop->shop_id, 'channel_category_id' => 'A'];
        $this->postJson("/api/v1/products/{$this->product->id}/channel-drafts", $payload)->assertStatus(201);

        $payload['channel_category_id'] = 'B';
        $this->postJson("/api/v1/products/{$this->product->id}/channel-drafts", $payload)->assertStatus(201);

        $this->assertSame(1, ProductChannelDraft::where('product_id', $this->product->id)->count());
        $this->assertSame('B', ProductChannelDraft::where('product_id', $this->product->id)->first()->channel_category_id);
    }

    public function test_store_unknown_shop_returns_422()
    {
        $response = $this->postJson("/api/v1/products/{$this->product->id}/channel-drafts", [
            'shop_id' => 'nope',
        ]);

        $response->assertStatus(422);
    }

    public function test_store_product_not_found_returns_404()
    {
        $response = $this->postJson('/api/v1/products/bogus/channel-drafts', [
            'shop_id' => $this->shop->shop_id,
        ]);

        $response->assertStatus(404);
    }

    public function test_index_lists_drafts_for_product()
    {
        $this->makeDraft();

        $response = $this->getJson("/api/v1/products/{$this->product->id}/channel-drafts");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
    }

    public function test_list_global_filter_by_status()
    {
        $this->makeDraft(ProductChannelDraft::STATUS_READY);

        $response = $this->getJson('/api/v1/products/channel-drafts?filter[status]=ready');

        $response->assertStatus(200);
        $statuses = collect($response->json('data'))->pluck('status')->unique()->values()->all();
        $this->assertSame(['ready'], $statuses);
    }

    public function test_update_draft()
    {
        $draft = $this->makeDraft();

        $response = $this->putJson("/api/v1/products/{$this->product->id}/channel-drafts/{$draft->id}", [
            'status' => 'ready',
            'price_override' => 999,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'ready');
        $this->assertSame('ready', $draft->fresh()->status);
    }

    public function test_destroy_draft()
    {
        $draft = $this->makeDraft();

        $response = $this->deleteJson("/api/v1/products/{$this->product->id}/channel-drafts/{$draft->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('product_channel_drafts', ['id' => $draft->id]);
    }

    private function makeDraft(string $status = ProductChannelDraft::STATUS_DRAFT): ProductChannelDraft
    {
        return ProductChannelDraft::create([
            'product_id' => $this->product->id,
            'channel_shop_id' => $this->shop->id,
            'channel_category_id' => 'CAT-X',
            'status' => $status,
        ]);
    }
}
