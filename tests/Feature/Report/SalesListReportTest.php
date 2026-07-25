<?php

namespace Tests\Feature\Report;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Channel\Models\ChannelShop;
use Modules\Report\Exports\SalesListPesananExport;
use Modules\Report\Services\SalesListReportService;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderItem;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class SalesListReportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Location $locA;
    private Location $locB;

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
    }

    private function order(array $attrs = []): SalesOrder
    {
        return SalesOrder::factory()->create(array_merge([
            'source'           => 'shopee',
            'location_id'      => $this->locA->id,
            'channel_shop_id'  => 'SHOP-1',
            'transaction_date' => '2026-07-20 10:00:00',
            'channel_status'   => 'COMPLETED',
        ], $attrs));
    }

    public function test_endpoint_requires_auth(): void
    {
        $this->getJson('/api/v1/reports/sales/list/export')->assertStatus(401);
    }

    public function test_export_endpoint_returns_xlsx(): void
    {
        $this->order();

        $response = $this->actingAs($this->user, 'sanctum')
            ->get('/api/v1/reports/sales/list/export?from=2026-07-01&to=2026-07-31');

        $response->assertOk();
        $this->assertStringContainsString('.xlsx', $response->headers->get('content-disposition'));
        $this->assertStringContainsString('Daftar-Penjualan', $response->headers->get('content-disposition'));
    }

    public function test_query_exposes_derived_columns(): void
    {
        $this->order();

        $row = app(SalesListReportService::class)
            ->query(['from' => '2026-07-01', 'to' => '2026-07-31'])
            ->get()
            ->first();

        $this->assertSame('Gudang Kecil', $row->loc_name);
        $this->assertSame('Toko Uji', $row->shop_label);
    }

    public function test_export_maps_20_columns_and_channel_label(): void
    {
        $order = $this->order([
            'total_disc'           => 65000,
            'transaction_fee'      => 5600,
            'order_processing_fee' => 1250,
            'sub_total'            => 100000,
            'grand_total'          => 28150,
        ]);

        $query = app(SalesListReportService::class)->query([]);
        $export = new SalesListPesananExport($query);

        $fetched = $query->get()->firstWhere('id', $order->id);
        $cells = $export->map($fetched);

        $this->assertCount(20, $cells);
        $this->assertSame('SHOPEE', $cells[3]);          
        $this->assertSame('Gudang Kecil', $cells[5]);    
        $this->assertSame('COMPLETED', $cells[9]);       
        $this->assertSame(65000.0, $cells[10]);          
        $this->assertSame(5600.0, $cells[12]);           
        $this->assertSame(1250.0, $cells[17]);           
        $this->assertSame(28150.0, $cells[19]);          
    }

    public function test_filters_by_location_ids(): void
    {
        $a = $this->order(['location_id' => $this->locA->id]);
        $this->order(['location_id' => $this->locB->id]);

        $rows = app(SalesListReportService::class)
            ->query(['location_ids' => [$this->locA->id]])
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame($a->id, $rows->first()->id);
    }

    public function test_filters_by_date_range(): void
    {
        $inRange = $this->order(['transaction_date' => '2026-07-15 09:00:00']);
        $this->order(['transaction_date' => '2026-06-01 09:00:00']);

        $rows = app(SalesListReportService::class)
            ->query(['from' => '2026-07-01', 'to' => '2026-07-31'])
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame($inRange->id, $rows->first()->id);
    }

    public function test_excludes_orders_with_undownloaded_sku(): void
    {
        $included = $this->order();

        $excluded = $this->order();
        SalesOrderItem::create([
            'order_id'    => $excluded->id,
            'item_id'     => null,
            'sku'         => 'BELUM-DOWNLOAD',
            'description' => 'Produk belum diunduh',
            'qty_in_base' => 1,
            'price'       => 1000,
            'amount'      => 1000,
        ]);

        $ids = app(SalesListReportService::class)->query([])->get()->pluck('id')->all();

        $this->assertContains($included->id, $ids);
        $this->assertNotContains($excluded->id, $ids);
    }

    public function test_channel_label_for_tiktok(): void
    {
        $order = $this->order(['source' => 'tiktok']);
        $query = app(SalesListReportService::class)->query([]);
        $export = new SalesListPesananExport($query);

        $cells = $export->map($query->get()->firstWhere('id', $order->id));

        $this->assertSame('Shop | Tokopedia', $cells[3]);
    }
}
