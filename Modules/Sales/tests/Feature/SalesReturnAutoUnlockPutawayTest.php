<?php

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inbound\Models\Inbound;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Services\PutawayService;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Services\SalesReturnService;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

class SalesReturnAutoUnlockPutawayTest extends TestCase
{
    use RefreshDatabase;

    private string $locationId;
    private LocationBin $inboundBin;
    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Umum']);

        $user = \App\Models\User::create([
            'id' => (string) Str::uuid(),
            'name' => 'Tester Putaway',
            'email' => 'tester-putaway@example.com',
            'password' => bcrypt('password'),
        ]);
        $this->userId = (string) $user->id;

        $location = Location::firstOrCreate(
            ['location_code' => 'WH-RET-LOC'],
            ['id' => (string) Str::uuid(), 'location_name' => 'Gudang Retur', 'location_type' => 'WAREHOUSE']
        );
        $this->locationId = (string) $location->id;

        $this->inboundBin = LocationBin::firstOrCreate(
            ['location_id' => $this->locationId, 'bin_final_code' => 'WH-RET-IN'],
            ['floor_code' => '1', 'row_code' => 'IN', 'column_code' => '1', 'bin_code' => 'IN', 'is_inbound' => true]
        );
    }

    public function test_accepted_sales_return_is_immediately_unlocked_and_can_be_assigned_for_putaway(): void
    {
        $product = Product::create([
            'name' => 'Produk Retur 1',
            'category_id' => 1,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
            'is_bundle' => false,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'RET-SKU-1',
            'is_active' => true,
        ]);

        $salesReturn = SalesReturn::create([
            'return_number' => 'RET-TEST-001',
            'location_id' => $this->locationId,
            'source' => SalesReturn::SOURCE_MANUAL,
            'status' => SalesReturn::STATUS_PENDING,
            'reason' => 'Salah ukuran',
            'created_by' => 'tester',
        ]);

        $salesReturn->items()->create([
            'item_id' => $variant->id,
            'qty' => 2,
            'condition' => 'GOOD',
        ]);

        // 1. Terima/Setujui Retur
        $returnService = app(SalesReturnService::class);
        $returnService->accept($salesReturn->id, [
            'processed_by' => 'warehouse_staff',
        ]);

        $salesReturn->refresh();
        $this->assertSame(SalesReturn::STATUS_ACCEPTED, $salesReturn->status);

        // 2. Cek Inbound yang dibuat: harus sudah RECEIVED dengan received_qty = 2
        $inbound = Inbound::where('source_type', 'sales_return')
            ->where('source_id', $salesReturn->id)
            ->with('items')
            ->first();

        $this->assertNotNull($inbound);
        $this->assertContains($inbound->status, [Inbound::STATUS_RECEIVED, Inbound::STATUS_COMPLETED], 'Inbound retur harus langsung berstatus RECEIVED / COMPLETED');
        $this->assertSame(2, (int) $inbound->items->first()->received_qty, 'Received qty harus terisi 2');

        // 3. Langsung Tugaskan Penempatan (Putaway) tanpa terima manual ulang
        $putawayService = app(PutawayService::class);
        $putaway = $putawayService->createFromInbounds([$inbound->id], null, $this->userId);

        $this->assertNotNull($putaway);
        $this->assertSame(2, (int) $putaway->items->first()->qty, 'Putaway harus menampung 2 pcs barang retur');
    }

    public function test_draft_sales_return_with_zero_received_can_still_be_directly_putawayed(): void
    {
        $product = Product::create([
            'name' => 'Produk Retur 2',
            'category_id' => 1,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
            'is_bundle' => false,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'RET-SKU-2',
            'is_active' => true,
        ]);

        // Buat Inbound Retur yang masih DRAFT dan received_qty = 0 (seperti data lama)
        $inbound = Inbound::create([
            'transaction_number' => 'INB-RET-DRAFT-001',
            'type' => Inbound::TYPE_SALES_RETURN,
            'source_type' => 'sales_return',
            'status' => Inbound::STATUS_DRAFT,
            'location_id' => $this->locationId,
            'expected_date' => now()->toDateString(),
            'created_by' => 'tester',
        ]);

        $inbound->items()->create([
            'item_id' => $variant->id,
            'expected_qty' => 3,
            'received_qty' => 0,
            'putaway_qty' => 0,
        ]);

        // Buat Putaway langsung dari Inbound DRAFT
        $putawayService = app(PutawayService::class);
        $putaway = $putawayService->createFromInbounds([$inbound->id], null, $this->userId);

        $this->assertNotNull($putaway);
        $this->assertSame(3, (int) $putaway->items->first()->qty);

        $inbound->refresh();
        $this->assertSame(Inbound::STATUS_RECEIVED, $inbound->status);
        $this->assertSame(3, (int) $inbound->items->first()->received_qty);
    }
}
