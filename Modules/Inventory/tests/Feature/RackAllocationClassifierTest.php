<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\RackImportBatch;
use Modules\Inventory\Services\RackImport\RackAllocationClassifier;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Modules\Warehouse\Services\BinMultiSkuRuleService;
use Tests\TestCase;

class RackAllocationClassifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->create();
        BinMultiSkuRuleService::flushPatternCache();
    }

    private function kecil(): Location
    {
        return Location::where('location_code', Location::SYSTEM_KECIL_CODE)->first()
            ?? Location::factory()->create(['location_code' => Location::SYSTEM_KECIL_CODE]);
    }

    private function bin(Location $loc, string $code, bool $inbound = false, bool $ack = true): LocationBin
    {
        return LocationBin::factory()->create([
            'location_id' => $loc->id,
            'is_inbound' => $inbound,
            'is_stock_acknowledged' => $ack,
            'bin_final_code' => $code,
        ]);
    }

    private function variant(): ProductVariant
    {
        $category = Category::create(['name' => 'Kat ' . Str::random(4)]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produk ' . Str::random(4),
            'sku' => 'P-' . Str::random(6),
            'status' => 'master',
        ]);

        return ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-' . Str::random(6),
        ]);
    }

    private function stock(Location $loc, ?LocationBin $bin, ProductVariant $v, int $onHand, int $onOrder = 0): void
    {
        Inventory::create([
            'item_id' => $v->id,
            'location_id' => $loc->id,
            'bin_id' => $bin?->id,
            'on_hand' => $onHand,
            'on_order' => $onOrder,
            'available' => max(0, $onHand - $onOrder),
        ]);
    }

    private function classifyOne(array $row): array
    {
        $classifier = app(RackAllocationClassifier::class);
        $result = $classifier->classify([['row_no' => 2] + $row]);

        return $result['records'][0];
    }

    public function test_valid_row_is_classified_as_place(): void
    {
        $loc = $this->kecil();
        $staging = $this->bin($loc, 'STAGE-1', inbound: true);
        $target = $this->bin($loc, 'O-A1-K1-X1');
        $v = $this->variant();
        $this->stock($loc, $staging, $v, 5); 

        $rec = $this->classifyOne(['sku' => $v->sku, 'location' => $loc->location_name, 'bin' => 'O-A1-K1-X1']);

        $this->assertSame(RackImportBatch::STATUS_PLACE, $rec['status']);
        $this->assertSame($target->id, $rec['bin_id']);
        $this->assertSame($v->id, $rec['item_id']);
    }

    public function test_unregistered_sku_is_error(): void
    {
        $loc = $this->kecil();
        $this->bin($loc, 'O-A1-K1-X1');

        $rec = $this->classifyOne(['sku' => 'TIDAK-ADA', 'location' => $loc->location_name, 'bin' => 'O-A1-K1-X1']);

        $this->assertSame(RackImportBatch::STATUS_ERROR, $rec['status']);
        $this->assertStringContainsString('SKU tidak terdaftar', $rec['message']);
    }

    public function test_unknown_location_is_error(): void
    {
        $this->kecil();
        $v = $this->variant();

        $rec = $this->classifyOne(['sku' => $v->sku, 'location' => 'Gudang Antah Berantah', 'bin' => 'O-A1']);

        $this->assertSame(RackImportBatch::STATUS_ERROR, $rec['status']);
        $this->assertStringContainsString('tidak ditemukan', $rec['message']);
    }

    public function test_unknown_bin_is_error(): void
    {
        $loc = $this->kecil();
        $v = $this->variant();

        $rec = $this->classifyOne(['sku' => $v->sku, 'location' => $loc->location_name, 'bin' => 'RAK-HANTU']);

        $this->assertSame(RackImportBatch::STATUS_ERROR, $rec['status']);
        $this->assertStringContainsString('tidak ada di lokasi ini', $rec['message']);
    }

    public function test_inbound_target_bin_is_error(): void
    {
        $loc = $this->kecil();
        $this->bin($loc, 'INB-1', inbound: true);
        $v = $this->variant();

        $rec = $this->classifyOne(['sku' => $v->sku, 'location' => $loc->location_name, 'bin' => 'INB-1']);

        $this->assertSame(RackImportBatch::STATUS_ERROR, $rec['status']);
        $this->assertStringContainsString('inbound', $rec['message']);
    }

    public function test_sku_already_in_target_bin_is_already(): void
    {
        $loc = $this->kecil();
        $home = $this->bin($loc, 'O-A1-K1-X1');
        $v = $this->variant();
        $this->stock($loc, $home, $v, 5);

        $rec = $this->classifyOne(['sku' => $v->sku, 'location' => $loc->location_name, 'bin' => 'O-A1-K1-X1']);

        $this->assertSame(RackImportBatch::STATUS_ALREADY, $rec['status']);
    }

    public function test_sku_placed_elsewhere_is_manual_move(): void
    {
        $loc = $this->kecil();
        $home = $this->bin($loc, 'O-A1-K1-X1');
        $this->bin($loc, 'O-B2-K1-X9');
        $v = $this->variant();
        $this->stock($loc, $home, $v, 5);

        $rec = $this->classifyOne(['sku' => $v->sku, 'location' => $loc->location_name, 'bin' => 'O-B2-K1-X9']);

        $this->assertSame(RackImportBatch::STATUS_MANUAL_MOVE, $rec['status']);
        $this->assertSame('O-A1-K1-X1', $rec['current_bin']);
    }

    public function test_bin_occupied_by_other_sku_is_error_in_strict_location(): void
    {
        $loc = $this->kecil();
        $target = $this->bin($loc, 'O-A1-K1-X1');
        $other = $this->variant();
        $this->stock($loc, $target, $other, 5); 

        $mine = $this->variant();
        $staging = $this->bin($loc, 'STAGE-2', inbound: true);
        $this->stock($loc, $staging, $mine, 3); 

        $rec = $this->classifyOne(['sku' => $mine->sku, 'location' => $loc->location_name, 'bin' => 'O-A1-K1-X1']);

        $this->assertSame(RackImportBatch::STATUS_ERROR, $rec['status']);
        $this->assertStringContainsString('1 rak 1 SKU', $rec['message']);
    }

    public function test_no_pending_stock_is_still_place(): void
    {
        $loc = $this->kecil();
        $target = $this->bin($loc, 'O-A1-K1-X1');
        $v = $this->variant();

        $rec = $this->classifyOne(['sku' => $v->sku, 'location' => $loc->location_name, 'bin' => 'O-A1-K1-X1']);

        $this->assertSame(RackImportBatch::STATUS_PLACE, $rec['status']);
        $this->assertSame($target->id, $rec['bin_id']);
    }
}
