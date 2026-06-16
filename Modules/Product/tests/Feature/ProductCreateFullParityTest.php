<?php

namespace Modules\Product\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Finance\Models\Account;
use Modules\Finance\Support\AccountMappingKey;
use Modules\Product\Models\Attribute;
use Modules\Product\Models\Brand;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Tax\Models\Tax;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class ProductCreateFullParityTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Category $category;
    protected Brand $brand;
    protected ChannelShop $shopA;
    protected ChannelShop $shopB;
    protected Account $revenue;
    protected Account $asset;
    protected Account $expense;
    protected Tax $salesTax;
    protected Tax $purchaseTax;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->category = Category::create(['name' => 'Aksesoris']);
        $this->brand = Brand::create(['name' => 'UltraFit']);

        $channel = Channel::create([
            'id' => Uuid::uuid7()->getHex()->toString(),
            'name' => 'Shopee',
            'code' => 'shopee',
            'auth_type' => 'oauth2',
        ]);
        $this->shopA = ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => 'SHOP-A',
            'shop_name' => 'Toko A',
            'is_active' => true,
        ]);
        $this->shopB = ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => 'SHOP-B',
            'shop_name' => 'Toko B',
            'is_active' => true,
        ]);

        $this->revenue = Account::create(['account_code' => '4-4000', 'account_name' => 'Pendapatan Penjualan', 'account_type' => 'revenue', 'is_active' => true]);
        $this->asset = Account::create(['account_code' => '1-1200', 'account_name' => 'Persediaan', 'account_type' => 'asset', 'is_active' => true]);
        $this->expense = Account::create(['account_code' => '5-5000', 'account_name' => 'HPP', 'account_type' => 'expense', 'is_active' => true]);

        $this->salesTax = Tax::create(['name' => 'PPN Keluaran', 'rate' => 11, 'is_active' => true]);
        $this->purchaseTax = Tax::create(['name' => 'PPN Masukan', 'rate' => 11, 'is_active' => true]);
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Resistance Band',
            'sku' => 'PRD-RB-01',
            'category_id' => $this->category->id,
            'brand_id' => $this->brand->id,
            'variants' => [[
                'sku' => 'RB-VAR-01',
                'sell_price' => 89000,
            ]],
        ], $overrides);
    }

    public function test_minimal_create_succeeds_as_master(): void
    {
        $res = $this->postJson('/api/v1/products', $this->basePayload());

        $res->assertCreated()->assertJsonPath('status', 'success');
        $id = $res->json('data.product_id');

        $this->assertDatabaseHas('products', ['id' => $id, 'status' => Product::STATUS_MASTER]);
        $this->assertDatabaseHas('product_variants', ['sku' => 'RB-VAR-01', 'sell_price' => 89000]);
    }

    public function test_full_parity_persists_all_fields(): void
    {
        $payload = $this->basePayload([
            'description' => 'Set resistance band premium',
            'order_type' => 'PREORDER',
            'indent_days' => 7,
            'is_stored' => true,
            'is_sold' => true,
            'is_purchased' => true,
            'purchase_lead_time' => 14,
            'package_contents' => '5 band + pouch',
            'weight' => 500,
            'length' => 20,
            'sales_account_id' => $this->revenue->id,
            'inventory_account_id' => $this->asset->id,
            'cogs_account_id' => $this->expense->id,
            'variants' => [[
                'sku' => 'RB-VAR-01',
                'barcode' => '8991234567890',
                'sell_price' => 89000,
                'buy_price' => 50000,
                'sales_tax_id' => $this->salesTax->id,
                'purchase_tax_id' => $this->purchaseTax->id,
                'min_stock' => 5,
                'safe_stock' => 10,
                'unlimited_shop_ids' => [$this->shopA->id, $this->shopB->id],
            ]],
        ]);

        $res = $this->postJson('/api/v1/products', $payload);
        $res->assertCreated();
        $id = $res->json('data.product_id');

        $this->assertDatabaseHas('products', [
            'id' => $id,
            'order_type' => 'PREORDER',
            'indent_days' => 7,
            'purchase_lead_time' => 14,
            'package_contents' => '5 band + pouch',
            'sales_account_id' => $this->revenue->id,
            'inventory_account_id' => $this->asset->id,
            'cogs_account_id' => $this->expense->id,
        ]);

        $variant = DB::table('product_variants')->where('sku', 'RB-VAR-01')->first();
        $this->assertEquals(50000, (int) $variant->buy_price);
        $this->assertEquals(5, $variant->min_stock);
        $this->assertEquals(10, $variant->safe_stock);
        $this->assertEquals($this->salesTax->id, $variant->sales_tax_id);
        $this->assertEquals($this->purchaseTax->id, $variant->purchase_tax_id);

        $this->assertEquals(11, (int) $variant->tax_rate);

        $this->assertEquals(2, DB::table('variant_unlimited_shops')->where('variant_id', $variant->id)->count());
        $this->assertDatabaseHas('variant_unlimited_shops', ['variant_id' => $variant->id, 'channel_shop_id' => $this->shopA->id]);
    }

    public function test_can_save_as_draft_download(): void
    {
        $res = $this->postJson('/api/v1/products', $this->basePayload(['status' => 'download']));
        $res->assertCreated();
        $this->assertDatabaseHas('products', ['id' => $res->json('data.product_id'), 'status' => Product::STATUS_DOWNLOAD]);
    }

    public function test_create_can_set_master_directly(): void
    {

        $res = $this->postJson('/api/v1/products', $this->basePayload(['status' => 'master']));

        $res->assertCreated();
        $this->assertDatabaseHas('products', [
            'id' => $res->json('data.product_id'),
            'status' => 'master',
        ]);
    }

    public function test_account_fallback_to_mapping_when_omitted(): void
    {
        DB::table('account_mappings')->updateOrInsert(
            ['key' => AccountMappingKey::SALES_REVENUE],
            ['id' => Uuid::uuid7()->toString(), 'account_id' => $this->revenue->id, 'updated_at' => now(), 'created_at' => now()]
        );

        $res = $this->postJson('/api/v1/products', $this->basePayload(['sku' => 'PRD-FB-01', 'variants' => [['sku' => 'FB-VAR-01', 'sell_price' => 1000]]]));
        $res->assertCreated();

        $this->assertDatabaseHas('products', [
            'id' => $res->json('data.product_id'),
            'sales_account_id' => $this->revenue->id,
        ]);
    }

    public function test_preorder_requires_indent_days(): void
    {
        $this->postJson('/api/v1/products', $this->basePayload(['order_type' => 'PREORDER']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('indent_days');
    }

    public function test_wrong_account_type_returns_422(): void
    {

        $this->postJson('/api/v1/products', $this->basePayload(['sales_account_id' => $this->asset->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('sales_account_id');
    }

    public function test_not_sold_and_not_purchased_is_allowed(): void
    {

        $this->postJson('/api/v1/products', $this->basePayload([
            'is_stored' => false,
            'is_sold' => false,
            'is_purchased' => false,
        ]))->assertCreated();
    }

    public function test_duplicate_variant_sku_returns_422_not_500(): void
    {
        $this->postJson('/api/v1/products', $this->basePayload())->assertCreated();

        $this->postJson('/api/v1/products', $this->basePayload(['sku' => 'PRD-RB-02']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('variants.0.sku');
    }

    public function test_invalid_tax_returns_422(): void
    {
        $payload = $this->basePayload();
        $payload['variants'][0]['sales_tax_id'] = 999999;

        $this->postJson('/api/v1/products', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('variants.0.sales_tax_id');
    }

    public function test_invalid_unlimited_shop_returns_422(): void
    {
        $payload = $this->basePayload();
        $payload['variants'][0]['unlimited_shop_ids'] = [Uuid::uuid7()->toString()];

        $this->postJson('/api/v1/products', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('variants.0.unlimited_shop_ids.0');
    }

    public function test_safe_stock_below_min_stock_returns_422(): void
    {
        $payload = $this->basePayload();
        $payload['variants'][0]['min_stock'] = 10;
        $payload['variants'][0]['safe_stock'] = 3;

        $this->postJson('/api/v1/products', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('variants.0.safe_stock');
    }
}
