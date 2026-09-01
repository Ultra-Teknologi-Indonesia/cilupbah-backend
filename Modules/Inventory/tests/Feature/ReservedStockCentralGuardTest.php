<?php

declare(strict_types=1);

namespace Modules\Inventory\Tests\Feature;

use App\Exceptions\UserFacingException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Inventory\Http\Requests\StoreReservedStockRequest;
use Modules\Inventory\Jobs\ProcessReservedStockJob;
use Modules\Inventory\Models\ReservedStock;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Inventory\Services\ReservedStockService;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class ReservedStockCentralGuardTest extends TestCase
{
    use RefreshDatabase;

    private string $centralLocationId;

    private string $variantId;

    private string $binId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centralLocationId = $this->createLocation();
        $this->variantId = $this->createVariant();
        $this->binId = $this->createBin();

        DB::table('inventories')->insert([
            'id' => Str::uuid()->toString(),
            'item_id' => $this->variantId,
            'location_id' => $this->centralLocationId,
            'bin_id' => $this->binId,
            'on_hand' => 10,
            'on_order' => 0,
            'available' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_request_rejects_central_warehouse(): void
    {
        $request = StoreReservedStockRequest::create('/api/v1/inventory/reserved-stocks', 'POST', [
            'start_date' => now()->toDateTimeString(),
            'end_date' => now()->addDay()->toDateTimeString(),
            'location_id' => $this->centralLocationId,
            'items' => [[
                'item_id' => $this->variantId,
                'bin_id' => $this->binId,
                'qty' => 1,
            ]],
        ]);

        $validator = Validator::make($request->all(), $request->rules());
        $request->withValidator($validator);

        $this->assertTrue($validator->fails());
        $this->assertSame(
            'Reservasi stok tidak dapat dibuat di Gudang Pusat.',
            $validator->errors()->first('location_id'),
        );
    }

    public function test_service_rejects_central_warehouse_before_creating_document(): void
    {
        try {
            app(ReservedStockService::class)->create([
                'start_date' => now(),
                'end_date' => now()->addDay(),
                'location_id' => $this->centralLocationId,
                'created_by' => 'tester',
                'items' => [[
                    'item_id' => $this->variantId,
                    'bin_id' => $this->binId,
                    'qty' => 1,
                ]],
            ]);

            $this->fail('Reservasi stok di Gudang Pusat seharusnya ditolak.');
        } catch (UserFacingException $exception) {
            $this->assertSame(
                'Reservasi stok tidak dapat dibuat di Gudang Pusat.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('reserved_stocks', 0);
        $this->assertSame(0, (int) DB::table('inventories')
            ->where('item_id', $this->variantId)
            ->where('location_id', $this->centralLocationId)
            ->value('on_order'));
    }

    public function test_queued_job_rejects_legacy_central_document_without_changing_inventory(): void
    {
        $reservedStock = ReservedStock::create([
            'reserved_stock_no' => 'RSV-CENTRAL-GUARD',
            'location_id' => $this->centralLocationId,
            'start_date' => now(),
            'end_date' => now()->addDay(),
            'status' => ReservedStock::STATUS_ACTIVE,
            'is_active' => true,
            'created_by' => 'legacy-test',
        ]);

        $reservedStock->items()->create([
            'item_id' => $this->variantId,
            'bin_id' => $this->binId,
            'qty' => 3,
        ]);

        try {
            (new ProcessReservedStockJob($reservedStock->id))->handle(
                app(InventoryRepository::class),
                app(InventoryMovementRepository::class),
            );

            $this->fail('Job legacy di Gudang Pusat seharusnya ditolak.');
        } catch (UserFacingException $exception) {
            $this->assertSame(
                'Reservasi stok di Gudang Pusat diblokir dan tidak mengubah on_order.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(0, (int) DB::table('inventories')
            ->where('item_id', $this->variantId)
            ->where('location_id', $this->centralLocationId)
            ->value('on_order'));
        $this->assertDatabaseMissing('inventory_movements', [
            'transaction_number' => 'RSV-CENTRAL-GUARD',
            'source' => 'RESERVE',
        ]);
    }

    private function createLocation(): string
    {
        $id = Str::uuid()->toString();

        DB::table('locations')->insert([
            'id' => $id,
            'location_code' => Location::SYSTEM_PUSAT_CODE,
            'location_name' => 'Gudang Pusat',
            'location_type' => 'WAREHOUSE',
            'is_warehouse' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createVariant(): string
    {
        DB::table('categories')->insertOrIgnore([
            'id' => 1,
            'name' => 'Kategori Reserved Stock',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = Str::uuid()->toString();
        DB::table('products')->insert([
            'id' => $productId,
            'category_id' => 1,
            'name' => 'Produk Reserved Stock',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $variantId = Str::uuid()->toString();
        DB::table('product_variants')->insert([
            'id' => $variantId,
            'product_id' => $productId,
            'sku' => 'SKU-RESERVED-CENTRAL-GUARD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $variantId;
    }

    private function createBin(): string
    {
        $id = Str::uuid()->toString();

        DB::table('location_bins')->insert([
            'id' => $id,
            'location_id' => $this->centralLocationId,
            'bin_final_code' => 'P-A1-K1-GUARD',
            'is_inbound' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
