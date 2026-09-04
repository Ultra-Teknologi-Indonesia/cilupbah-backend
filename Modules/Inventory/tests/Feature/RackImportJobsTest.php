<?php

namespace Modules\Inventory\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Inventory\Jobs\ApplyRackChunkJob;
use Modules\Inventory\Jobs\PreviewRackImportJob;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\RackImportBatch;
use Modules\Inventory\Models\RackImportRow;
use Modules\Inventory\Services\RackImport\RackImportBatchService;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Modules\Warehouse\Services\BinMultiSkuRuleService;
use Modules\Inventory\Models\SkuRackAssignment;
use Modules\Inventory\Services\RackImport\RackAssignmentService;
use Modules\Warehouse\Services\SkuHomeBinGuard;
use Tests\TestCase;

class RackImportJobsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        BinMultiSkuRuleService::flushPatternCache();
    }

    private function kecil(): Location
    {
        $location = Location::where('location_code', Location::SYSTEM_KECIL_CODE)->first()
            ?? Location::factory()->smallWarehouse()->create(['location_code' => Location::SYSTEM_KECIL_CODE]);

        if (! $location->is_small_warehouse) {
            $location->forceFill(['is_small_warehouse' => true])->save();
            $location->refresh();
        }

        return $location;
    }

    private function bin(Location $loc, string $code, bool $inbound = false): LocationBin
    {
        return LocationBin::factory()->create([
            'location_id' => $loc->id,
            'is_inbound' => $inbound,
            'is_stock_acknowledged' => true,
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

    private function stock(Location $loc, ?LocationBin $bin, ProductVariant $v, int $onHand): void
    {
        Inventory::create([
            'item_id' => $v->id,
            'location_id' => $loc->id,
            'bin_id' => $bin?->id,
            'on_hand' => $onHand,
            'on_order' => 0,
            'available' => $onHand,
        ]);
    }

    public function test_preview_job_stages_rows_and_counts(): void
    {
        Storage::fake(RackImportBatchService::DISK);

        $loc = $this->kecil();
        $staging = $this->bin($loc, 'STAGE-1', inbound: true);
        $this->bin($loc, 'O-A1-K1-X1');
        $v = $this->variant();
        $this->stock($loc, $staging, $v, 5);

        $csv = "SKU,Lokasi,Rak\n"
            . "{$v->sku},{$loc->location_name},O-A1-K1-X1\n"
            . "BOGUS-SKU,{$loc->location_name},O-A1-K1-X1\n";

        $path = RackImportBatchService::DIR . '/test.csv';
        Storage::disk(RackImportBatchService::DISK)->put($path, $csv);

        $batch = RackImportBatch::create([
            'batch_no' => 'RAK-TEST-' . Str::random(4),
            'executed_by' => $this->user->id,
            'original_filename' => 'test.csv',
            'stored_path' => $path,
            'state' => RackImportBatch::STATE_QUEUED,
        ]);

        PreviewRackImportJob::dispatchSync($batch->id);

        $batch->refresh();
        $this->assertSame(RackImportBatch::STATE_PREVIEWED, $batch->state);
        $this->assertSame(2, $batch->total_rows);
        $this->assertSame(1, $batch->place_rows);
        $this->assertSame(1, $batch->error_rows);
        $this->assertSame(2, RackImportRow::where('batch_id', $batch->id)->count());
    }

    public function test_apply_job_only_saves_assignment_and_does_not_move_pending_stock(): void
    {
        $loc = $this->kecil();
        $staging = $this->bin($loc, 'STAGE-1', inbound: true);
        $target = $this->bin($loc, 'O-A1-K1-X1');
        $v = $this->variant();
        $this->stock($loc, $staging, $v, 5);

        $batch = RackImportBatch::create([
            'batch_no' => 'RAK-TEST-' . Str::random(4),
            'executed_by' => $this->user->id,
            'original_filename' => 'test.csv',
            'stored_path' => 'x',
            'state' => RackImportBatch::STATE_CONFIRMING,
            'place_rows' => 1,
        ]);

        $row = RackImportRow::create([
            'batch_id' => $batch->id,
            'row_no' => 2,
            'raw_sku' => $v->sku,
            'raw_location' => $loc->location_name,
            'raw_bin' => 'O-A1-K1-X1',
            'item_id' => $v->id,
            'location_id' => $loc->id,
            'bin_id' => $target->id,
            'status' => RackImportBatch::STATUS_PLACE,
            'created_at' => now(),
        ]);

        $stockBefore = (int) Inventory::where('item_id', $v->id)
            ->where('location_id', $loc->id)
            ->where('bin_id', $staging->id)
            ->value('on_hand');
        $movementCountBefore = \DB::table('inventory_movements')
            ->where('item_id', $v->id)
            ->where('location_id', $loc->id)
            ->count();

        ApplyRackChunkJob::dispatchSync($batch->id, [$row->id]);

        $row->refresh();
        $batch->refresh();
        $this->assertSame(RackImportBatch::STATUS_PLACED, $row->status);
        $this->assertSame(1, $batch->success_rows);
        $this->assertSame($target->id, app(SkuHomeBinGuard::class)->currentHomeBinId($loc->id, $v->id));
        $this->assertSame($stockBefore, (int) Inventory::where('item_id', $v->id)
            ->where('location_id', $loc->id)
            ->where('bin_id', $staging->id)
            ->value('on_hand'));
        $this->assertSame($movementCountBefore, \DB::table('inventory_movements')
            ->where('item_id', $v->id)
            ->where('location_id', $loc->id)
            ->count());

        ApplyRackChunkJob::dispatchSync($batch->id, [$row->id]);
        $batch->refresh();
        $this->assertSame(1, $batch->success_rows);
        $this->assertSame($target->id, app(SkuHomeBinGuard::class)->currentHomeBinId($loc->id, $v->id));
    }

    public function test_apply_job_without_stock_records_assignment_and_opens_gate(): void
    {
        $loc = $this->kecil();
        $target = $this->bin($loc, 'O-A1-K1-X1');
        $v = $this->variant(); 

        $batch = RackImportBatch::create([
            'batch_no' => 'RAK-TEST-' . Str::random(4),
            'executed_by' => $this->user->id,
            'original_filename' => 'test.csv',
            'stored_path' => 'x',
            'state' => RackImportBatch::STATE_CONFIRMING,
            'place_rows' => 1,
        ]);

        $row = RackImportRow::create([
            'batch_id' => $batch->id,
            'row_no' => 2,
            'raw_sku' => $v->sku,
            'raw_location' => $loc->location_name,
            'raw_bin' => 'O-A1-K1-X1',
            'item_id' => $v->id,
            'location_id' => $loc->id,
            'bin_id' => $target->id,
            'status' => RackImportBatch::STATUS_PLACE,
            'created_at' => now(),
        ]);

        ApplyRackChunkJob::dispatchSync($batch->id, [$row->id]);

        $row->refresh();
        $batch->refresh();
        $this->assertSame(RackImportBatch::STATUS_PLACED, $row->status);
        $this->assertSame(1, (int) $batch->success_rows);

        $this->assertSame($target->id, app(SkuHomeBinGuard::class)->currentHomeBinId($loc->id, $v->id));
        $this->assertDatabaseHas('sku_rack_assignments', [
            'location_id' => $loc->id,
            'item_id' => $v->id,
            'bin_id' => $target->id,
        ]);
    }

    public function test_planned_assignment_enforces_one_rack_one_sku(): void
    {
        $loc = $this->kecil();
        $rak = $this->bin($loc, 'O-A1-K1-X1');
        $a = $this->variant();
        $b = $this->variant();

        $svc = app(RackAssignmentService::class);
        $svc->assign($loc->id, $rak->id, $a->id, $this->user->id);

        $this->expectException(\DomainException::class);
        $svc->assign($loc->id, $rak->id, $b->id, $this->user->id);
    }

    public function test_soft_deleted_variant_no_longer_blocks_a_rack_assignment(): void
    {
        $loc = $this->kecil();
        $rak = $this->bin($loc, 'O-A1-K1-X2');
        $old = $this->variant();
        $new = $this->variant();

        SkuRackAssignment::create([
            'location_id' => $loc->id,
            'item_id' => $old->id,
            'bin_id' => $rak->id,
            'assigned_by' => $this->user->id,
        ]);
        $old->delete();

        app(RackAssignmentService::class)->assign($loc->id, $rak->id, $new->id, $this->user->id);

        $this->assertDatabaseHas('sku_rack_assignments', [
            'location_id' => $loc->id,
            'item_id' => $new->id,
            'bin_id' => $rak->id,
        ]);
    }

    public function test_start_confirm_is_atomic(): void
    {
        $batch = RackImportBatch::create([
            'batch_no' => 'RAK-TEST-' . Str::random(4),
            'executed_by' => $this->user->id,
            'original_filename' => 'test.csv',
            'stored_path' => 'x',
            'state' => RackImportBatch::STATE_PREVIEWED,
            'place_rows' => 3,
        ]);

        $service = app(RackImportBatchService::class);

        $this->assertTrue($service->startConfirm($batch));
        $this->assertSame(RackImportBatch::STATE_CONFIRMING, $batch->fresh()->state);
        $this->assertFalse($service->startConfirm($batch->fresh()));
    }
}
