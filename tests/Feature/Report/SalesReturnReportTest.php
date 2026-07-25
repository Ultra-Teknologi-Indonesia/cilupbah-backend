<?php

namespace Tests\Feature\Report;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Report\Exports\SalesReturnExport;
use Modules\Report\Services\SalesReturnReportService;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderItem;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Models\SalesReturnItem;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class SalesReturnReportTest extends TestCase
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
        $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $role->givePermissionTo('view-laporan-penjualan');

        $this->user = User::factory()->create();
        $this->user->assignRole($role);

        $this->locA = Location::factory()->create(['location_name' => 'Gudang Kecil']);
        $this->locB = Location::factory()->create(['location_name' => 'Gudang Besar']);

        ChannelShop::create(['shop_id' => 'SHOP-1', 'shop_name' => 'iCase Store']);

        $category = Category::create(['name' => 'Case', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Matte Hybrid Case',
            'status' => 'master',
            'is_active' => true,
        ]);
        $this->variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'MATTE-S-BLUE-IP-XSMAX',
            'sell_price' => 50000,
            'is_active' => true,
        ]);
    }

    private function makeReturn(
        string $createdAt = '2026-07-15 10:00:00',
        ?string $locationId = null,
        string $returnNo = 'SR-000104337',
        string $orderNo = 'TT-584674136249173913-73644',
    ): SalesReturnItem {
        $locationId ??= $this->locA->id;

        $order = SalesOrder::factory()->create([
            'source'          => 'tiktok',
            'channel_shop_id' => 'SHOP-1',
            'salesorder_no'   => $orderNo,
        ]);
        SalesOrderItem::create([
            'order_id'     => $order->id,
            'item_id'      => $this->variant->id,
            'sku'          => $this->variant->sku,
            'description'  => 'Matte Hybrid Magnetic Case',
            'qty_in_base'  => 1,
            'price'        => 50000,
            'disc'         => 40000,
            'amount'       => 10000,
        ]);

        $return = SalesReturn::create([
            'return_number'          => $returnNo,
            'order_id'               => $order->id,
            'location_id'            => $locationId,
            'source'                 => 'tiktok',
            'channel_shop_id'        => 'SHOP-1',
            'return_tracking_number' => 'JX9777581903',
            'customer_name'          => 'Yuni',
            'notes'                  => 'barang rusak',
            'status'                 => SalesReturn::STATUS_ACCEPTED,
            'created_by'             => $this->user->id,
        ]);
        $return->forceFill(['created_at' => $createdAt])->save();

        return SalesReturnItem::create([
            'sales_return_id' => $return->id,
            'item_id'         => $this->variant->id,
            'qty'             => 2,
        ]);
    }

    public function test_endpoint_requires_auth(): void
    {
        $this->getJson('/api/v1/reports/sales/return/export')->assertStatus(401);
    }

    public function test_export_endpoint_returns_xlsx(): void
    {
        $this->makeReturn();

        $response = $this->actingAs($this->user, 'sanctum')
            ->get('/api/v1/reports/sales/return/export?from=2026-07-01&to=2026-07-31');

        $response->assertOk();
        $this->assertStringContainsString('.xlsx', $response->headers->get('content-disposition'));
        $this->assertStringContainsString('Daftar-Retur-Penjualan', $response->headers->get('content-disposition'));
    }

    public function test_export_maps_20_columns_with_prices_from_original_line(): void
    {
        $item = $this->makeReturn();

        $query = app(SalesReturnReportService::class)->query([]);
        $export = new SalesReturnExport($query);
        $row = $query->get()->firstWhere('id', $item->id);
        $cells = $export->map($row);

        $this->assertCount(20, $cells);
        $this->assertSame('Gudang Kecil', $cells[1]);                 
        $this->assertSame('SR-000104337', $cells[2]);                 
        $this->assertSame('TT-584674136249173913-73644', $cells[4]);  
        $this->assertSame('Shop | Tokopedia', $cells[5]);             
        $this->assertSame('iCase Store', $cells[6]);                  
        $this->assertSame('JX9777581903', $cells[8]);                 
        $this->assertSame('MATTE-S-BLUE-IP-XSMAX', $cells[11]);       
        $this->assertSame(2.0, $cells[13]);                           
        $this->assertSame(50000.0, $cells[14]);                       
        $this->assertSame(40000.0, $cells[15]);                       
        $this->assertSame(100000.0, $cells[17]);                      
        $this->assertSame(20000.0, $cells[18]);                       
        $this->assertSame(20000.0, $cells[19]);                       
    }

    public function test_filters_by_location_and_date(): void
    {
        $inScope = $this->makeReturn('2026-07-10 09:00:00', $this->locA->id, 'SR-1', 'TT-1');
        $this->makeReturn('2026-07-10 09:00:00', $this->locB->id, 'SR-2', 'TT-2');
        $this->makeReturn('2026-06-01 09:00:00', $this->locA->id, 'SR-3', 'TT-3');

        $rows = app(SalesReturnReportService::class)
            ->query(['location_ids' => [$this->locA->id], 'from' => '2026-07-01', 'to' => '2026-07-31'])
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame($inScope->id, $rows->first()->id);
    }
}
