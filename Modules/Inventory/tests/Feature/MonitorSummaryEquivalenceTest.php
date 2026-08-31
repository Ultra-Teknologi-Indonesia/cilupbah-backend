<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Repositories\MonitorStockRepository;
use Modules\Inventory\Support\StockSummary;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

class MonitorSummaryEquivalenceTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    private LocationBin $bin;

    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::create([
            'location_code' => 'WH-MON', 'location_name' => 'Gudang Monitor',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);

        $this->bin = LocationBin::create([
            'location_id' => $this->location->id, 'bin_code' => 'A',
            'bin_final_code' => 'WH-MON-A',
        ]);

        $this->categoryId = DB::table('categories')->insertGetId([
            'name' => 'Cat Monitor', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeVariant(string $sku, int $onHand, int $available, int $minStock = 0): ProductVariant
    {
        $product = Product::create([
            'category_id' => $this->categoryId,
            'name' => 'P-'.$sku,
            'sku' => 'P-'.$sku,
            'is_active' => true,
            'is_stored' => true,
            'is_bundle' => false,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => $sku,
            'min_stock' => $minStock,
        ]);

        Inventory::create([
            'item_id' => $variant->id,
            'location_id' => $this->location->id,
            'bin_id' => $this->bin->id,
            'on_hand' => $onHand,
            'on_order' => max(0, $onHand - $available),
            'available' => $available,
            'avg_cost' => 100,
        ]);

        return $variant;
    }

    private function legacySummary(MonitorStockRepository $repo, array $filters): array
    {
        return [
            'habis' => $repo->countMode('habis', $filters),
            'minus' => $repo->countMode('minus', $filters),
            'dipesan' => $repo->countMode('dipesan', $filters),
            'menipis' => $repo->countMode('menipis', $filters),
            'on_order' => $repo->countMode('on-order', $filters),
        ];
    }

    public function test_single_pass_summary_matches_the_five_separate_counts(): void
    {
        $habis = $this->makeVariant('SKU-HABIS', 0, 0);
        $this->makeVariant('SKU-MINUS', 5, -3);
        $this->makeVariant('SKU-MENIPIS', 5, 5, 10);
        $this->makeVariant('SKU-AMAN', 100, 100, 10);
        $dipesan = $this->makeVariant('SKU-DIPESAN', 0, 0);
        $onOrder = $this->makeVariant('SKU-PO', 50, 50);

        $orderId = (string) Str::uuid();
        DB::table('sales_orders')->insert([
            'id' => $orderId,
            'salesorder_no' => 'SO-MON-1',
            'customer_name' => 'Buyer',
            'status' => 'UNPAID',
            'sub_total' => 0, 'total_disc' => 0, 'total_tax' => 0,
            'shipping_cost' => 0, 'insurance_cost' => 0, 'grand_total' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sales_order_items')->insert([
            'id' => (string) Str::uuid(),
            'order_id' => $orderId,
            'item_id' => $dipesan->id,
            'qty_in_base' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $contactId = (string) Str::uuid();
        DB::table('contacts')->insert([
            'id' => $contactId,
            'code' => 'SUP-MON-1',
            'name' => 'Pemasok Monitor',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $poId = (string) Str::uuid();
        DB::table('purchase_orders')->insert([
            'id' => $poId,
            'po_number' => 'PO-MON-1',
            'contact_id' => $contactId,
            'location_id' => $this->location->id,
            'status' => 'OPEN',
            'order_date' => now()->toDateString(),
            'created_by' => User::query()->value('id') ?? User::factory()->create()->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('purchase_order_items')->insert([
            'id' => (string) Str::uuid(),
            'purchase_order_id' => $poId,
            'item_id' => $onOrder->id,
            'qty' => 5,
            'received_qty' => 0,
            'unit_price' => 1000,
            'amount' => 5000,
            'disc' => 0,
            'disc_amount' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $repo = app(MonitorStockRepository::class);

        foreach ([[], ['location_id' => $this->location->id]] as $filters) {
            $this->assertSame(
                $this->legacySummary($repo, $filters),
                $repo->summary($filters),
                'Ringkasan satu-lintasan wajib memberi angka yang sama dengan lima COUNT terpisah.',
            );
        }

        $summary = $repo->summary([]);

        $this->assertGreaterThan(0, $summary['habis'], 'Data uji harus menyentuh cabang habis.');
        $this->assertSame(1, $summary['minus'], 'Data uji harus menyentuh cabang minus.');
        $this->assertSame(1, $summary['menipis'], 'Data uji harus menyentuh cabang menipis.');
        $this->assertSame(1, $summary['dipesan'], 'Data uji harus menyentuh cabang dipesan.');
        $this->assertSame(1, $summary['on_order'], 'Data uji harus menyentuh cabang on_order.');

        $this->assertNotNull($habis);
    }

    public function test_summary_runs_a_single_aggregate_query(): void
    {
        $this->makeVariant('SKU-A', 0, 0);
        $this->makeVariant('SKU-B', 5, 5, 10);

        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        app(MonitorStockRepository::class)->summary([]);

        $this->assertSame(
            1,
            $count,
            'Ringkasan harus satu query. Kalau jadi lima lagi, regresi performanya kembali.',
        );
    }

    public function test_summary_excludes_unplaced_stock_and_keeps_negative_available(): void
    {
        $variant = $this->makeVariant('SKU-SCOPE', 10, 10);

        Inventory::where('item_id', $variant->id)
            ->where('location_id', $this->location->id)
            ->where('bin_id', $this->bin->id)
            ->update(['on_order' => 15]);

        $inbound = LocationBin::create([
            'location_id' => $this->location->id,
            'bin_code' => 'DEFAULT',
            'bin_final_code' => 'DEFAULT',
            'is_inbound' => true,
        ]);

        Inventory::create([
            'item_id' => $variant->id,
            'location_id' => $this->location->id,
            'bin_id' => null,
            'on_hand' => 100,
            'on_order' => 0,
        ]);
        Inventory::create([
            'item_id' => $variant->id,
            'location_id' => $this->location->id,
            'bin_id' => $inbound->id,
            'on_hand' => 200,
            'on_order' => 0,
        ]);

        $summary = StockSummary::forItem($variant->id, $this->location->id);

        $this->assertSame(10, $summary['on_hand']);
        $this->assertSame(200, $summary['pending_placement']);
        $this->assertSame(100, $summary['legacy_unassigned']);
        $this->assertSame(210, $summary['physical_total']);
        $this->assertSame(15, $summary['on_order']);
        $this->assertSame(-5, $summary['available']);

        $this->assertSame(
            200,
            (int) Inventory::where('item_id', $variant->id)->pendingPlacement()->sum('on_hand'),
            'Scope pending placement hanya boleh memuat bin inbound yang sah.',
        );
        $this->assertSame(
            100,
            (int) Inventory::where('item_id', $variant->id)->legacyUnassigned()->sum('on_hand'),
            'Data tanpa rak wajib terisolasi dari alur penempatan.',
        );
    }
}
