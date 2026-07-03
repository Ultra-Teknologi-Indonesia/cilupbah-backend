<?php

namespace Modules\Product\Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class ProductE2ETest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Category $category;
    protected ChannelShop $channelShop;
    protected string $testProductId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->category = Category::create([
            'id' => Uuid::uuid7()->getHex()->toString(),
            'name' => 'Kategori E2E'
        ]);

        $channel = Channel::create([
            'id' => Uuid::uuid7()->getHex()->toString(),
            'name' => 'TikTok E2E',
            'code' => 'tiktok',
            'auth_type' => 'oauth2'
        ]);

        $this->channelShop = ChannelShop::create([
            'id' => Uuid::uuid7()->getHex()->toString(),
            'channel_id' => $channel->id,
            'shop_id' => 'SHPE2E123',
            'shop_name' => 'Toko TikTok E2E',
            'is_active' => true
        ]);
    }

    public function test_can_create_product_with_variants_and_overrides()
    {
        $payload = [
            'name' => 'Produk E2E Bundle',
            'sku' => 'E2E-PROD-01',
            'category_id' => $this->category->id,
            'description' => 'Deskripsi produk E2E',
            'weight' => 2.5,
            'length' => 10,
            'width' => 10,
            'height' => 10,
            'is_active' => true,
            'is_bundle' => true,
            'is_consignment' => false,
            'media' => [['url' => 'https://img.test/a.jpg', 'media_type' => 'image']],
            'variants' => [
                [
                    'sku' => 'E2E-VAR-01',
                    'sell_price' => 100000,
                    'is_active' => true,
                    'channel_prices' => [
                        [
                            'channel_shop_id' => $this->channelShop->id,
                            'price' => 125000
                        ]
                    ]
                ]
            ]
        ];

        $response = $this->postJson('/api/v1/products', $payload);

         $response->assertStatus(201)
                 ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('products', [
            'name' => 'Produk E2E Bundle',
            'is_bundle' => true,
            'is_consignment' => false,
        ]);

        $this->assertDatabaseHas('product_variants', [
            'sku' => 'E2E-VAR-01',
            'sell_price' => 100000,
        ]);
    }

    public function test_can_read_product_detail()
    {

        $createResponse = $this->postJson('/api/v1/products', [
            'name' => 'Produk E2E Read',
            'sku' => 'E2E-PROD-READ',
            'category_id' => $this->category->id,
            'media' => [['url' => 'https://img.test/a.jpg', 'media_type' => 'image']],
            'weight' => 1,
            'length' => 1,
            'width' => 1,
            'height' => 1,
            'variants' => [
                [
                    'sku' => 'E2E-VAR-READ',
                    'sell_price' => 50000,
                    'channel_prices' => [
                        [
                            'channel_shop_id' => $this->channelShop->id,
                            'price' => 55000
                        ]
                    ]
                ]
            ]
        ]);

        $productId = $createResponse->json('data.product_id');

        $readResponse = $this->getJson("/api/v1/products/{$productId}");

         $readResponse->assertStatus(200)
                     ->assertJsonPath('data.name', 'Produk E2E Read')
                     ->assertJsonPath('data.variants.0.sku', 'E2E-VAR-READ')
                     ->assertJsonPath('data.variants.0.sell_price', '50000.00');
    }

    public function test_can_update_product_and_overrides()
    {

        $createResponse = $this->postJson('/api/v1/products', [
            'name' => 'Produk E2E Awal',
            'sku' => 'E2E-PROD-UPDATE',
            'category_id' => $this->category->id,
            'media' => [['url' => 'https://img.test/a.jpg', 'media_type' => 'image']],
            'variants' => [
                [
                    'sku' => 'E2E-VAR-UPDATE',
                    'sell_price' => 10000,
                    'channel_prices' => [
                        [
                            'channel_shop_id' => $this->channelShop->id,
                            'price' => 15000
                        ]
                    ]
                ]
            ]
        ]);

        $productId = $createResponse->json('data.product_id');

        $updateResponse = $this->putJson("/api/v1/products/{$productId}", [
            'name' => 'Produk E2E Baru',
            'category_id' => $this->category->id,
            'variants' => [
                [
                    'sku' => 'E2E-VAR-UPDATE',
                    'sell_price' => 20000, 
                    'channel_prices' => [
                        [
                            'channel_shop_id' => $this->channelShop->id,
                            'price' => 30000 
                        ]
                    ]
                ]
            ]
        ]);

        $updateResponse->assertStatus(200);

        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'name' => 'Produk E2E Baru',
        ]);

        $this->assertDatabaseHas('product_variants', [
            'sku' => 'E2E-VAR-UPDATE',
            'sell_price' => 20000,
        ]);
    }

    public function test_can_delete_product()
    {

        $createResponse = $this->postJson('/api/v1/products', [
            'name' => 'Produk E2E Hapus',
            'sku' => 'E2E-PROD-DELETE',
            'category_id' => $this->category->id,
            'media' => [['url' => 'https://img.test/a.jpg', 'media_type' => 'image']],
            'variants' => [
                [
                    'sku' => 'E2E-VAR-DELETE',
                    'sell_price' => 5000,
                    'channel_prices' => [
                        [
                            'channel_shop_id' => $this->channelShop->id,
                            'price' => 7000
                        ]
                    ]
                ]
            ]
        ]);

        $productId = $createResponse->json('data.product_id');

        $deleteResponse = $this->deleteJson("/api/v1/products/{$productId}");

        $deleteResponse->assertStatus(200);

        $this->assertSoftDeleted('products', [
            'id' => $productId,
        ]);

        $this->assertNull(Product::find($productId));
        $this->assertNotNull(Product::withTrashed()->find($productId));

        $this->assertDatabaseHas('product_variants', [
            'sku' => 'E2E-VAR-DELETE',
        ]);
    }

    public function test_cannot_delete_product_that_still_has_stock_on_hand()
    {

        $createResponse = $this->postJson('/api/v1/products', [
            'name' => 'Produk Masih Bergerak',
            'sku' => 'E2E-PROD-STOCK',
            'category_id' => $this->category->id,
            'media' => [['url' => 'https://img.test/a.jpg', 'media_type' => 'image']],
            'variants' => [
                [
                    'sku' => 'E2E-VAR-STOCK',
                    'sell_price' => 5000,
                    'channel_prices' => [
                        [
                            'channel_shop_id' => $this->channelShop->id,
                            'price' => 7000
                        ]
                    ]
                ]
            ]
        ]);

        $productId = $createResponse->json('data.product_id');
        $variant = Product::find($productId)->variants()->firstOrFail();

        $location = \Modules\Warehouse\Models\Location::create([
            'location_code' => 'WH-E2E-01',
            'location_name' => 'Gudang E2E',
            'location_type' => 'warehouse',
        ]);

        \Modules\Inventory\Models\Inventory::create([
            'item_id' => $variant->id,
            'location_id' => $location->id,
            'on_hand' => 10,
            'available' => 10,
        ]);

        $deleteResponse = $this->deleteJson("/api/v1/products/{$productId}");

        $deleteResponse->assertStatus(422);
        $this->assertNotNull(Product::find($productId), 'Produk tidak boleh terhapus saat masih ada stok');
    }

    public function test_cannot_create_product_with_invalid_payload()
    {

        $response = $this->postJson('/api/v1/products', [
            'name' => '', 
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['name', 'category_id', 'variants']);
    }

    public function test_cannot_read_non_existent_product()
    {
        $response = $this->getJson('/api/v1/products/not-a-real-id-1234');
        $response->assertStatus(404);
    }

    public function test_can_list_products()
    {

        $this->postJson('/api/v1/products', [
            'name' => 'Produk List 1',
            'sku' => 'E2E-PROD-LIST1',
            'category_id' => $this->category->id,
            'media' => [['url' => 'https://img.test/a.jpg', 'media_type' => 'image']],
            'variants' => [['sku' => 'LIST1', 'sell_price' => 1000]]
        ]);
        $this->postJson('/api/v1/products', [
            'name' => 'Produk List 2',
            'sku' => 'E2E-PROD-LIST2',
            'category_id' => $this->category->id,
            'media' => [['url' => 'https://img.test/a.jpg', 'media_type' => 'image']],
            'variants' => [['sku' => 'LIST2', 'sell_price' => 2000]]
        ]);

        $response = $this->getJson('/api/v1/products?limit=10');
         $response->assertStatus(200)
                 ->assertJsonPath('status', 'success');

        $data = $response->json('data');
        $this->assertGreaterThanOrEqual(2, count($data));
    }

    public function test_can_approve_archive_restore_product()
    {
        $createResponse = $this->postJson('/api/v1/products', [
            'name' => 'Produk Lifecycle',
            'sku' => 'E2E-PROD-LIFECYCLE',
            'category_id' => $this->category->id,
            'variants' => [['sku' => 'LIFECYCLE1', 'sell_price' => 5000]],
            'media' => [['url' => 'http://example.com/image.jpg', 'is_primary' => true]]
        ]);
        $productId = $createResponse->json('data.product_id');

        \DB::table('products')->where('id', $productId)->update(['status' => 'download']);

        $approveRes = $this->postJson("/api/v1/products/{$productId}/approve");
        $approveRes->assertStatus(200);
        $this->assertDatabaseHas('products', ['id' => $productId, 'status' => 'master']);

        $archiveRes = $this->postJson("/api/v1/products/{$productId}/archive", [
            'reason' => 'Testing archive'
        ]);
        $archiveRes->assertStatus(200);
        $this->assertDatabaseHas('products', ['id' => $productId, 'status' => 'archived']);

        \DB::table('products')->where('id', $productId)->update(['status' => 'archived']);
        $restoreRes = $this->postJson("/api/v1/products/{$productId}/restore");
        $restoreRes->assertStatus(200);
        $this->assertDatabaseHas('products', ['id' => $productId, 'status' => 'master']);
    }
}
