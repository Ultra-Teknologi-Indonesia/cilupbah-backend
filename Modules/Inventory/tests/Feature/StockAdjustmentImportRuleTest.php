<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Services\StockAdjustmentImportService;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class StockAdjustmentImportRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_uses_manual_policy_and_returns_warning_for_allowed_negative_result(): void
    {
        config(['inventory.allow_negative_stock' => true]);
        $fixture = $this->createFixture(-53);

        $preview = app(StockAdjustmentImportService::class)->preview(
            $this->createXlsx($fixture['variant']->sku, $fixture['bin']->bin_final_code, -2),
            $fixture['location']->id,
        );

        $this->assertCount(0, $preview['errors']);
        $this->assertCount(1, $preview['items']);
        $this->assertSame(-55, $preview['items'][0]['actual_qty']);
        $this->assertSame(1, $preview['summary']['warnings']);
    }

    public function test_import_rejects_the_same_negative_result_when_manual_policy_disallows_it(): void
    {
        config(['inventory.allow_negative_stock' => false]);
        $fixture = $this->createFixture(5);

        $preview = app(StockAdjustmentImportService::class)->preview(
            $this->createXlsx($fixture['variant']->sku, $fixture['bin']->bin_final_code, -10),
            $fixture['location']->id,
        );

        $this->assertCount(1, $preview['errors']);
        $this->assertSame(0, $preview['items'] ? count($preview['items']) : 0);
        $this->assertStringContainsString('on_hand: 5, adjustment: -10, hasil: -5', $preview['errors'][0]['error']);
    }

    private function createFixture(int $onHand): array
    {
        $location = Location::create([
            'location_code' => 'WH-IMPORT-RULE',
            'location_name' => 'Gudang Import Rule',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_active' => true,
        ]);
        $bin = LocationBin::create([
            'location_id' => $location->id,
            'bin_code' => 'A',
            'bin_final_code' => 'WH-IMPORT-RULE-A',
        ]);
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Cat Import Rule',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId,
            'name' => 'Product Import Rule',
            'sku' => 'P-IMPORT-RULE',
            'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'V-IMPORT-RULE',
        ]);
        Inventory::create([
            'item_id' => $variant->id,
            'location_id' => $location->id,
            'bin_id' => $bin->id,
            'on_hand' => $onHand,
            'on_order' => 0,
            'available' => $onHand,
            'avg_cost' => 100,
        ]);

        return compact('location', 'bin', 'variant');
    }

    private function createXlsx(string $sku, string $binCode, int $delta): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(StockAdjustmentImportService::DATA_SHEET_NAME);
        $sheet->fromArray([
            ['item_code', 'bin_final_code', 'delta_qty', 'final_qty', 'hpp', 'notes'],
            [$sku, $binCode, $delta, '', '', 'Test rule'],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'stock-adjustment-import-');
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile(
            $path,
            'stock-adjustment.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
    }
}
