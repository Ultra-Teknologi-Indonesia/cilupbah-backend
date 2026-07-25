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
use Modules\Report\Exports\RincianPendapatanExport;
use Modules\Report\Exports\RincianPendapatanPerBarangExport;
use Modules\Report\Services\RincianPendapatanReportService;
use Modules\Sales\Models\SalesInvoice;
use Modules\Sales\Models\SalesInvoiceItem;
use Modules\Sales\Models\SalesOrder;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class RincianPendapatanReportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Location $loc;
    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'view-laporan-penjualan', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $role->givePermissionTo('view-laporan-penjualan');

        $this->user = User::factory()->create();
        $this->user->assignRole($role);

        $this->loc = Location::factory()->create(['location_name' => 'Gudang Kecil']);
        ChannelShop::create(['shop_id' => 'SHOP-1', 'shop_name' => 'iCase Store']);

        $category = Category::create(['name' => 'Case', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Ultrafit Glass Case',
            'status' => 'master',
            'is_active' => true,
        ]);
        $this->variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'AG-BLACK-IP-16',
            'sell_price' => 100000,
            'is_active' => true,
        ]);
    }

    private function makeInvoice(string $tgl = '2026-07-11 23:29:38'): SalesInvoiceItem
    {
        $order = SalesOrder::factory()->create([
            'source'               => 'shopee',
            'channel_shop_id'      => 'SHOP-1',
            'location_id'          => $this->loc->id,
            'salesorder_no'        => 'SP-260712E5555P08',
            'transaction_date'     => $tgl,
            'channel_status'       => 'COMPLETED',
            'customer_name'        => 'M. Barda',
            'sub_total'            => 200000,
            'total_disc'           => 110000,
            'platform_voucher'     => 0,
            'transaction_fee'      => 7700,
            'service_fee'          => 0,
            'order_processing_fee' => 1250,
            'total_tax'            => 0,
            'shipping_cost'        => 0,
            'insurance_cost'       => 0,
            'settlement_amount'    => 17650,
            'price_includes_tax'   => false,
        ]);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-006817356',
            'order_id'       => $order->id,
            'customer_name'  => 'M. Barda',
            'location_id'    => $this->loc->id,
            'status'         => SalesInvoice::STATUS_OPEN,
            'invoice_date'   => '2026-07-11',
            'total_amount'   => 90000,
            'created_by'     => $this->user->id,
        ]);

        return SalesInvoiceItem::create([
            'sales_invoice_id' => $invoice->id,
            'item_id'          => $this->variant->id,
            'qty'              => 1,
            'unit_price'       => 100000,
            'disc_amount'      => 45000,
            'tax_amount'       => 0,
            'subtotal'         => 100000,
            'cogs_per_unit'    => 1000,
            'total_cogs'       => 1000,
        ]);
    }

    public function test_endpoint_requires_auth(): void
    {
        $this->getJson('/api/v1/reports/sales/income/export')->assertStatus(401);
    }

    public function test_rincian_mode_maps_23_columns_with_profit(): void
    {
        $this->makeInvoice();

        $query = app(RincianPendapatanReportService::class)->query('rincian', []);
        $cells = (new RincianPendapatanExport($query))->map($query->get()->first());

        $this->assertCount(23, $cells);
        $this->assertSame('FAKTUR', $cells[1]);
        $this->assertSame('SP-260712E5555P08', $cells[2]);       
        $this->assertSame('INV-006817356', $cells[3]);           
        $this->assertSame('SHOPEE', $cells[6]);                  
        $this->assertSame(200000.0, $cells[9]);                  
        $this->assertSame(110000.0, $cells[10]);                 
        $this->assertSame('TIDAK', $cells[14]);                  
        $this->assertSame(90000.0, $cells[18]);                  
        $this->assertSame(1000.0, $cells[19]);                   
        $this->assertSame(89000.0, $cells[20]);                  
        $this->assertSame(17650.0, $cells[21]);                  
    }

    public function test_per_barang_mode_maps_28_columns_with_profit(): void
    {
        $item = $this->makeInvoice();

        $query = app(RincianPendapatanReportService::class)->query('per_barang', []);
        $row = $query->get()->firstWhere('id', $item->id);
        $cells = (new RincianPendapatanPerBarangExport($query))->map($row);

        $this->assertCount(28, $cells);
        $this->assertSame('AG-BLACK-IP-16', $cells[10]);   
        $this->assertSame(1.0, $cells[11]);                
        $this->assertSame(100000.0, $cells[12]);           
        $this->assertSame(45000.0, $cells[14]);            
        $this->assertSame(7700.0, $cells[19]);             
        $this->assertSame(1250.0, $cells[23]);             
        $this->assertSame(1000.0, $cells[25]);             
        $this->assertSame(46050.0, $cells[26]);            
        $this->assertSame(45050.0, $cells[27]);            
    }

    public function test_per_barang_filters_by_item_ids(): void
    {
        $keep = $this->makeInvoice();

        $rows = app(RincianPendapatanReportService::class)
            ->query('per_barang', ['item_ids' => ['00000000-0000-0000-0000-000000000000']])
            ->get();
        $this->assertCount(0, $rows);

        $rows2 = app(RincianPendapatanReportService::class)
            ->query('per_barang', ['item_ids' => [$this->variant->id]])
            ->get();
        $this->assertCount(1, $rows2);
        $this->assertSame($keep->id, $rows2->first()->id);
    }

    public function test_export_endpoint_returns_xlsx_for_both_modes(): void
    {
        $this->makeInvoice();

        foreach (['rincian', 'per_barang'] as $mode) {
            $response = $this->actingAs($this->user, 'sanctum')
                ->get("/api/v1/reports/sales/income/export?jenis={$mode}&from=2026-07-01&to=2026-07-31");
            $response->assertOk();
            $this->assertStringContainsString('.xlsx', $response->headers->get('content-disposition'));
        }
    }
}
