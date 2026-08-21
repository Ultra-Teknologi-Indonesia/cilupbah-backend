<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Inventory\Jobs\ProcessPutawayItemJob;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\Putaway;
use Modules\Inventory\Models\PutawayItem;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

class PutawayNegativeStockTest extends TestCase
{
    use RefreshDatabase;

    private function seedFixture(): array
    {
        Queue::fake();

        $location = Location::create([
            'location_code' => 'WH-PUT-NEG', 'location_name' => 'Gudang Putaway Neg',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);

        $sourceBin = LocationBin::create([
            'location_id' => $location->id, 'bin_code' => 'SRC',
            'bin_final_code' => 'WH-PUT-NEG-SRC', 'is_inbound' => true,
        ]);
        $destBin = LocationBin::create([
            'location_id' => $location->id, 'bin_code' => 'DST',
            'bin_final_code' => 'WH-PUT-NEG-DST', 'is_inbound' => false,
        ]);

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Cat Put Neg', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId, 'name' => 'P-Put-Neg', 'sku' => 'P-Put-Neg', 'is_active' => true,
        ]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'V-Put-Neg']);

        $userId = Str::uuid()->toString();
        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Tester',
            'email' => 'tester+' . substr($userId, 0, 6) . '@example.test',
            'password' => bcrypt('secret'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $putaway = Putaway::create([
            'putaway_no' => 'PUT-NEG-' . Str::upper(Str::random(6)),
            'location_id' => $location->id,
            'source_type' => 'MANUAL',
            'status' => Putaway::STATUS_IN_PROGRESS,
            'created_by' => $userId,
        ]);

        $putawayItem = PutawayItem::create([
            'putaway_id' => $putaway->id,
            'item_id' => $variant->id,
            'source_bin_id' => $sourceBin->id,
            'qty' => 50,
            'putaway_qty' => 0,
        ]);

        return compact('location', 'sourceBin', 'destBin', 'variant', 'putaway', 'putawayItem');
    }

    public function test_putaway_from_empty_source_bin_succeeds_when_negative_allowed(): void
    {
        config(['inventory.allow_negative_stock' => true]);
        $ctx = $this->seedFixture();

        (new ProcessPutawayItemJob(
            $ctx['putaway']->id,
            $ctx['putawayItem']->id,
            ['destination_bin_id' => $ctx['destBin']->id, 'qty' => 50],
        ))->handle(
            app(\Modules\Inventory\Repositories\PutawayRepository::class),
            app(\Modules\Inventory\Repositories\InventoryRepository::class),
            app(\Modules\Inventory\Repositories\InventoryMovementRepository::class),
        );

        $sourceInv = Inventory::where('bin_id', $ctx['sourceBin']->id)
            ->where('item_id', $ctx['variant']->id)->first();
        $this->assertNotNull($sourceInv);
        $this->assertSame(-50, (int) $sourceInv->on_hand);

        $destInv = Inventory::where('bin_id', $ctx['destBin']->id)
            ->where('item_id', $ctx['variant']->id)->first();
        $this->assertNotNull($destInv);
        $this->assertSame(50, (int) $destInv->on_hand);

        $this->assertDatabaseHas('inventory_movements', [
            'bin_id' => $ctx['sourceBin']->id,
            'source' => 'PUTAWAY_OUT',
            'qty' => -50,
            'balance' => -50,
            'transaction_number' => $ctx['putaway']->putaway_no,
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'bin_id' => $ctx['destBin']->id,
            'source' => 'PUTAWAY_IN',
            'qty' => 50,
            'balance' => 50,
            'transaction_number' => $ctx['putaway']->putaway_no,
        ]);

        $this->assertSame(50, (int) $ctx['putawayItem']->fresh()->putaway_qty);
        $this->assertSame(Putaway::STATUS_COMPLETED, $ctx['putaway']->fresh()->status);
    }

    public function test_putaway_from_empty_storage_bin_throws_when_negative_disallowed(): void
    {
        config(['inventory.allow_negative_stock' => false]);
        $ctx = $this->seedFixture();
        $ctx['sourceBin']->update(['is_inbound' => false]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Stok di source bin tidak mencukupi');

        (new ProcessPutawayItemJob(
            $ctx['putaway']->id,
            $ctx['putawayItem']->id,
            ['destination_bin_id' => $ctx['destBin']->id, 'qty' => 50],
        ))->handle(
            app(\Modules\Inventory\Repositories\PutawayRepository::class),
            app(\Modules\Inventory\Repositories\InventoryRepository::class),
            app(\Modules\Inventory\Repositories\InventoryMovementRepository::class),
        );
    }

    public function test_putaway_from_inbound_staging_bin_succeeds_even_when_negative_disallowed(): void
    {
        config(['inventory.allow_negative_stock' => false]);
        $ctx = $this->seedFixture();

        (new ProcessPutawayItemJob(
            $ctx['putaway']->id,
            $ctx['putawayItem']->id,
            ['destination_bin_id' => $ctx['destBin']->id, 'qty' => 50],
        ))->handle(
            app(\Modules\Inventory\Repositories\PutawayRepository::class),
            app(\Modules\Inventory\Repositories\InventoryRepository::class),
            app(\Modules\Inventory\Repositories\InventoryMovementRepository::class),
        );

        $this->assertSame(50, (int) $ctx['putawayItem']->fresh()->putaway_qty);
        $this->assertSame(Putaway::STATUS_COMPLETED, $ctx['putaway']->fresh()->status);
    }
}
