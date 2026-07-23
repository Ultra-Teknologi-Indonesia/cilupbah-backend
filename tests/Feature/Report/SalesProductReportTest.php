<?php

namespace Tests\Feature\Report;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductMedia;
use Modules\Product\Models\ProductVariant;
use Modules\Report\Exports\SalesProductExport;
use Modules\Report\Services\SalesProductReportService;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderItem;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class SalesProductReportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Location $locA;
    private Location $locB;
    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'view-laporan-penjualan', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'export-laporan-penjualan', 'guard_name' => 'web']);

        $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $role->givePermissionTo(['view-laporan-penjualan', 'export-laporan-penjualan']);

        $this->user = User::factory()->create();
        $this->user->assignRole($role);

        $this->locA = Location::factory()->create(['location_name' => 'Gudang Kecil']);
        $this->locB = Location::factory()->create(['location_name' => 'Gudang Besar']);

        ChannelShop::create(['shop_id' => 'SHOP-1', 'shop_name' => 'Toko Uji']);

        $category = Category::create(['name' => 'Case', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Case Keren',
            'status' => 'master',
            'is_active' => true,
        ]);
        $this->variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'CASE-BLACK-15',
            'sell_price' => 50000,
            'is_active' => true,
        ]);
    }

    private function orderWithItem(array $orderAttrs = [], array $itemAttrs = []): SalesOrderItem
    {
        $order = SalesOrder::factory()->create(array_merge([
            'source'           => 'shopee',
            'location_id'      => $this->locA->id,
            'channel_shop_id'  => 'SHOP-1',
            'transaction_date' => '2026-07-15 10:00:00',
            'channel_status'   => 'COMPLETED',
            'customer_name'    => 'Sari',
            'buyer_message'    => 'warna hitam ya',
        ], $orderAttrs));

        return SalesOrderItem::create(array_merge([
            'order_id'     => $order->id,
            'item_id'      => $this->variant->id,
            'sku'          => $this->variant->sku,
            'description'  => 'Case Keren',
            'qty_in_base'  => 2,
            'price'        => 50000,
            'amount'       => 100000,
        ], $itemAttrs));
    }

    public function test_export_endpoint_requires_auth(): void
    {
        $this->getJson('/api/v1/reports/sales/product/export')->assertStatus(401);
    }

    public function test_export_endpoint_returns_xlsx(): void
    {
        $this->orderWithItem();

        $response = $this->actingAs($this->user, 'sanctum')
            ->get('/api/v1/reports/sales/product/export?from=2026-07-01&to=2026-07-31');

        $response->assertOk();
        $this->assertStringContainsString('.xlsx', $response->headers->get('content-disposition'));
        $this->assertStringContainsString('Daftar-Penjualan-Produk', $response->headers->get('content-disposition'));
    }

    public function test_export_maps_13_columns(): void
    {
        $item = $this->orderWithItem();

        $query = app(SalesProductReportService::class)->query([]);
        $export = new SalesProductExport($query);
        $row = $query->get()->firstWhere('id', $item->id);
        $cells = $export->map($row);

        $this->assertCount(13, $cells);
        $this->assertSame('CASE-BLACK-15', $cells[0]);   // SKU
        $this->assertSame('Case Keren', $cells[1]);       // Nama Barang
        $this->assertSame('Gudang Kecil', $cells[3]);     // Lokasi
        $this->assertSame('SHOPEE', $cells[4]);           // Sumber
        $this->assertSame('Sari', $cells[6]);             // Pelanggan
        $this->assertSame(2.0, $cells[8]);                // QTY
        $this->assertSame(100000.0, $cells[9]);           // amount
        $this->assertSame('Toko Uji', $cells[10]);        // Nama Toko
        $this->assertSame('COMPLETED', $cells[11]);       // Status
        $this->assertSame('warna hitam ya', $cells[12]);  // Catatan
    }

    public function test_filters_by_item_ids(): void
    {
        $keep = $this->orderWithItem();

        $otherVariant = ProductVariant::create([
            'product_id' => $this->variant->product_id,
            'sku' => 'CASE-WHITE-15',
            'sell_price' => 50000,
            'is_active' => true,
        ]);
        $this->orderWithItem([], ['item_id' => $otherVariant->id, 'sku' => 'CASE-WHITE-15']);

        $rows = app(SalesProductReportService::class)
            ->query(['item_ids' => [$this->variant->id]])
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame($keep->id, $rows->first()->id);
    }

    public function test_filters_by_location_and_date(): void
    {
        $inScope = $this->orderWithItem();
        $this->orderWithItem(['location_id' => $this->locB->id]);
        $this->orderWithItem(['transaction_date' => '2026-06-01 10:00:00']);

        $rows = app(SalesProductReportService::class)
            ->query(['location_ids' => [$this->locA->id], 'from' => '2026-07-01', 'to' => '2026-07-31'])
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame($inScope->id, $rows->first()->id);
    }

    public function test_sku_options_endpoint_paginates_with_image(): void
    {
        ProductMedia::create([
            'product_id' => $this->variant->product_id,
            'variant_id' => $this->variant->id,
            'url'        => 'https://cdn.example.test/case.jpg',
            'is_primary' => true,
            'sort_order' => 0,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/reports/sales/product/sku-options?search=CASE-BLACK&per_page=20');

        $response->assertOk()
            ->assertJsonPath('data.0.sku', 'CASE-BLACK-15')
            ->assertJsonPath('data.0.name', 'Case Keren')
            ->assertJsonPath('data.0.image_url', 'https://cdn.example.test/case.jpg')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('data.0.id', $this->variant->id);
    }
}
