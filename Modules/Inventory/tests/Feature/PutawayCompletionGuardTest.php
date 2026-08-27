<?php

namespace Modules\Inventory\Tests\Feature;

use App\Exceptions\UserFacingException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\Putaway;
use Modules\Inventory\Models\PutawayItem;
use Modules\Inventory\Services\PutawayService;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

class PutawayCompletionGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_putaway_cannot_be_completed_with_remaining_qty(): void
    {
        $location = Location::create([
            'location_code' => 'WH-COMPLETE-GUARD',
            'location_name' => 'Gudang Completion Guard',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_active' => true,
        ]);

        $sourceBin = LocationBin::create([
            'location_id' => $location->id,
            'bin_code' => 'DEFAULT',
            'bin_final_code' => 'DEFAULT',
            'is_inbound' => true,
        ]);

        $category = Category::create(['name' => 'Kategori Completion Guard']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produk Completion Guard',
            'sku' => 'PRODUCT-COMPLETION-GUARD',
            'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'VARIANT-COMPLETION-GUARD',
        ]);

        $putaway = Putaway::create([
            'putaway_no' => 'PUT-COMPLETION-GUARD',
            'location_id' => $location->id,
            'source_type' => 'MANUAL',
            'status' => Putaway::STATUS_IN_PROGRESS,
            'created_by' => 'system',
        ]);

        PutawayItem::create([
            'putaway_id' => $putaway->id,
            'item_id' => $variant->id,
            'source_bin_id' => $sourceBin->id,
            'qty' => 3,
            'putaway_qty' => 1,
        ]);

        try {
            app(PutawayService::class)->complete($putaway->id);
            $this->fail('Putaway parsial tidak boleh berhasil diselesaikan.');
        } catch (UserFacingException $exception) {
            $this->assertSame('Penempatan belum selesai', $exception->getTitle());
            $this->assertSame(422, $exception->getStatus());
            $this->assertSame(2, $exception->getErrors()['remaining_qty']);
        }

        $this->assertSame(Putaway::STATUS_IN_PROGRESS, $putaway->fresh()->status);
    }
}
