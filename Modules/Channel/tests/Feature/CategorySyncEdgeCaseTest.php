<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ChannelListingValidator;
use Modules\Channel\Services\LazadaProductService;
use Modules\Channel\Services\LazadaToInternalProductMapper;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class CategorySyncEdgeCaseTest extends TestCase
{
    use RefreshDatabase;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.lazada.app_key' => 'k',
            'services.lazada.app_secret' => 's',
            'services.lazada.base_url' => 'https://api.lazada.co.id/rest',
        ]);
        $this->channel = Channel::create(['code' => 'lazada', 'name' => 'Lazada']);
        ChannelShop::create([
            'channel_id' => $this->channel->id, 'shop_id' => 'LZ1', 'shop_name' => 'Toko',
            'access_token' => 'tok', 'is_active' => true,
        ]);
    }

    /**
     * Satu fake dengan closure penghitung — sync ke-N memakai tree ke-N.
     * (Http::fake berulang = first-match-wins, jadi tak bisa di-override per panggilan.)
     */
    private function fakeTreeSequence(array $trees): void
    {
        $i = 0;
        Http::fake(['*category/tree/get*' => function () use (&$i, $trees) {
            $tree = $trees[min($i, count($trees) - 1)];
            $i++;

            return Http::response(['data' => $tree], 200);
        }]);
    }

    private function sync(): void
    {
        app(LazadaProductService::class)->syncCategoryTree('LZ1');
    }

    public function test_disappeared_category_is_soft_deprecated_not_deleted(): void
    {
        $this->fakeTreeSequence([
            [['category_id' => 'CAT-A', 'name' => 'A', 'leaf' => true], ['category_id' => 'CAT-B', 'name' => 'B', 'leaf' => true]],
            [['category_id' => 'CAT-A', 'name' => 'A', 'leaf' => true]],
        ]);
        $this->sync();
        $this->assertDatabaseCount('channel_categories', 2);

        // Petakan kategori internal → CAT-B.
        $catB = DB::table('channel_categories')->where('external_id', 'CAT-B')->first();
        $internal = Category::create(['name' => 'Internal']);
        DB::table('category_channel_mappings')->insert([
            'category_id' => $internal->id, 'channel_category_id' => $catB->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Re-sync TANPA CAT-B.
        $this->sync();

        // Tidak dihapus; CAT-B deprecated, CAT-A aktif.
        $this->assertDatabaseCount('channel_categories', 2);
        $this->assertNotNull(DB::table('channel_categories')->where('external_id', 'CAT-B')->value('deprecated_at'));
        $this->assertNull(DB::table('channel_categories')->where('external_id', 'CAT-A')->value('deprecated_at'));

        // Mapping ke CAT-B jadi stale.
        $this->assertTrue((bool) DB::table('category_channel_mappings')->where('channel_category_id', $catB->id)->value('is_stale'));
    }

    public function test_reappeared_category_is_reactivated(): void
    {
        $this->fakeTreeSequence([
            [['category_id' => 'CAT-B', 'name' => 'B', 'leaf' => true]],
            [['category_id' => 'CAT-X', 'name' => 'X', 'leaf' => true]],
            [['category_id' => 'CAT-B', 'name' => 'B', 'leaf' => true]],
        ]);
        $this->sync();
        $catB = DB::table('channel_categories')->where('external_id', 'CAT-B')->first();
        $internal = Category::create(['name' => 'Internal']);
        DB::table('category_channel_mappings')->insert([
            'category_id' => $internal->id, 'channel_category_id' => $catB->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Hilang → deprecated.
        $this->sync();
        $this->assertNotNull(DB::table('channel_categories')->where('external_id', 'CAT-B')->value('deprecated_at'));

        // Muncul lagi → aktif kembali, mapping segar.
        $this->sync();
        $this->assertNull(DB::table('channel_categories')->where('external_id', 'CAT-B')->value('deprecated_at'));
        $this->assertFalse((bool) DB::table('category_channel_mappings')->where('channel_category_id', $catB->id)->value('is_stale'));
        $this->assertNotNull(DB::table('category_channel_mappings')->where('channel_category_id', $catB->id)->value('last_verified_at'));
    }

    public function test_deprecated_category_blocks_listing(): void
    {
        $internal = Category::create(['name' => 'HP']);
        $ccId = Uuid::uuid7()->toString();
        DB::table('channel_categories')->insert([
            'id' => $ccId, 'channel_id' => $this->channel->id, 'external_id' => 'CAT-DEP',
            'parent_external_id' => '0', 'name' => 'HP Lama', 'is_leaf' => true,
            'deprecated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('category_channel_mappings')->insert([
            'category_id' => $internal->id, 'channel_category_id' => $ccId, 'is_stale' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $product = Product::create([
            'name' => 'HP', 'category_id' => $internal->id, 'status' => Product::STATUS_MASTER,
            'is_active' => true, 'weight' => 0.3,
        ]);
        ProductVariant::create(['product_id' => $product->id, 'sku' => 'HP-1', 'sell_price' => 1000, 'is_active' => true]);

        $issues = app(ChannelListingValidator::class)->validate($product, 'lazada');

        $this->assertSame('category_deprecated', $issues[0]['code']);
    }

    public function test_unknown_inbound_category_falls_back_to_uncategorized(): void
    {
        $internal = app(LazadaToInternalProductMapper::class)
            ->map(['primary_category' => 'TIDAK-ADA', 'attributes' => ['name' => 'Barang']], 'LZ1');

        $uncategorized = DB::table('categories')->where('name', 'Belum Dikategorikan')->value('id');
        $this->assertNotNull($uncategorized);
        $this->assertSame($uncategorized, $internal['category_id']);
    }
}
