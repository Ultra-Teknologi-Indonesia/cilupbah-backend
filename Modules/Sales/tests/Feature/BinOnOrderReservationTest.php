<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Http\Resources\InventoryStockResource;
use Modules\Inventory\Http\Resources\StockItemResource;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\SkuRackAssignment;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Sales\Services\StockService;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

class BinOnOrderReservationTest extends TestCase
{
    use RefreshDatabase;

    private string $kecilId;
    private string $pusatId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Umum']);

        $kecil = Location::firstOrCreate(
            ['location_code' => Location::SYSTEM_KECIL_CODE],
            ['id' => (string) Str::uuid(), 'location_name' => 'Gudang Kecil', 'location_type' => 'WAREHOUSE']
        );
        $this->kecilId = (string) $kecil->id;

        $pusat = Location::firstOrCreate(
            ['location_code' => 'PUSAT'],
            ['id' => (string) Str::uuid(), 'location_name' => 'Gudang Pusat', 'location_type' => 'WAREHOUSE']
        );
        $this->pusatId = (string) $pusat->id;
    }

    public function test_reserve_allocates_on_order_directly_to_sku_assigned_rack_in_gudang_kecil(): void
    {
        $product = Product::create([
            'name' => 'Earphone ME570FAW',
            'category_id' => 1,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
            'is_bundle' => false,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'ME570FAW',
            'is_active' => true,
        ]);

        $binKecil = LocationBin::create([
            'location_id' => $this->kecilId,
            'bin_code' => 'O-LX-KX-KANTOR',
            'bin_final_code' => 'O-LX-KX-KANTOR',
            'floor_code' => '1',
            'row_code' => 'LX',
            'column_code' => 'KX',
            'is_inbound' => false,
        ]);

        SkuRackAssignment::create([
            'item_id' => $variant->id,
            'location_id' => $this->kecilId,
            'bin_id' => $binKecil->id,
        ]);

        Inventory::create([
            'item_id' => $variant->id,
            'location_id' => $this->kecilId,
            'bin_id' => $binKecil->id,
            'on_hand' => 24,
            'on_order' => 0,
            'available' => 24,
        ]);

        $binPusat = LocationBin::create([
            'location_id' => $this->pusatId,
            'bin_code' => 'IN-G1-K1-P1',
            'bin_final_code' => 'IN-G1-K1-P1',
            'floor_code' => '1',
            'row_code' => 'G1',
            'column_code' => 'K1',
            'is_inbound' => false,
        ]);

        Inventory::create([
            'item_id' => $variant->id,
            'location_id' => $this->pusatId,
            'bin_id' => $binPusat->id,
            'on_hand' => 600,
            'on_order' => 0,
            'available' => 600,
        ]);

        $stockService = app(StockService::class);
        $stockService->reserve('ME570FAW', $variant->id, $this->kecilId, 1, 'SO-TEST-001');

        $invKecil = Inventory::where('item_id', $variant->id)
            ->where('location_id', $this->kecilId)
            ->where('bin_id', $binKecil->id)
            ->first();

        $this->assertNotNull($invKecil);
        $this->assertSame(24, (int) $invKecil->on_hand);
        $this->assertSame(1, (int) $invKecil->on_order, 'On order harus tercatat pada baris rak fisik');
        $this->assertSame(23, (int) $invKecil->available, 'Available rak fisik harus berkurang jadi 23');

        $aggRow = Inventory::where('item_id', $variant->id)
            ->where('location_id', $this->kecilId)
            ->whereNull('bin_id')
            ->first();
        $this->assertTrue($aggRow === null || (int) $aggRow->on_order === 0);

        $stocks = app(InventoryRepository::class)->getByItem($variant->id);
        $resourceStocks = InventoryStockResource::collectionWithActual($stocks);

        $rowKecil = collect($resourceStocks)->firstWhere('bin_code', 'O-LX-KX-KANTOR');
        $this->assertNotNull($rowKecil);
        $this->assertSame(24, $rowKecil['on_hand']);
        $this->assertSame(1, $rowKecil['on_order'], 'Resource rak harus mengembalikan On Order 1');
        $this->assertSame(23, $rowKecil['available'], 'Resource rak harus mengembalikan Available 23');

        $variantDetail = app(InventoryRepository::class)->findVariantWithStockDetail($variant->id);
        $summaryResource = (new StockItemResource($variantDetail))->resolve();

        $this->assertSame(624, $summaryResource['total_stocks']['on_hand']); 
        $this->assertSame(1, $summaryResource['total_stocks']['on_order']);
        $this->assertSame(623, $summaryResource['total_stocks']['available']); 

        $locKecil = collect($summaryResource['location_stocks'])->firstWhere('location_id', $this->kecilId);
        $this->assertSame(24, $locKecil['on_hand']);
        $this->assertSame(1, $locKecil['on_order']);
        $this->assertSame(23, $locKecil['available']);

        $stockService->pick('ME570FAW', $variant->id, $this->kecilId, 1, 'SO-TEST-001');

        $invKecil->refresh();
        $this->assertSame(0, (int) $invKecil->on_order, 'On order harus kembali 0 setelah pick');
        $this->assertSame(24, (int) $invKecil->available);
    }
}
