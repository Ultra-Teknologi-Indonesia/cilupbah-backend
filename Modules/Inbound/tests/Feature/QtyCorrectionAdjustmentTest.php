<?php

namespace Modules\Inbound\Tests\Feature;

use App\Enums\ClientChannelEnum;
use App\Exceptions\UserFacingException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inbound\Models\Inbound;
use Modules\Inbound\Models\InboundItem;
use Modules\Inbound\Models\InboundReceipt;
use Modules\Inbound\Services\InboundService;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Services\InventoryService;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

class QtyCorrectionAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    private LocationBin $inboundBin;

    private ProductVariant $variant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::create([
            'location_code' => 'WH-QC', 'location_name' => 'Gudang QC',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);

        $this->inboundBin = LocationBin::create([
            'location_id' => $this->location->id, 'bin_code' => 'IN',
            'bin_final_code' => 'WH-QC-IN', 'is_inbound' => true,
        ]);

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Cat QC', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId, 'name' => 'P-QC', 'sku' => 'P-QC', 'is_active' => true,
        ]);
        $this->variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'V-QC']);

        $this->admin = User::factory()->create(['name' => 'ADMIN QC']);
    }

    private function makeReceivedInbound(int $receivedQty): Inbound
    {
        $inbound = Inbound::create([
            'location_id' => $this->location->id,
            'transaction_number' => 'INB-QC-'.fake()->unique()->numerify('######'),
            'type' => Inbound::TYPE_PURCHASE_ORDER,
            'source_type' => 'purchase_order',
            'status' => Inbound::STATUS_DRAFT,
            'expected_date' => now(),
            'created_by' => 'admin',
        ]);

        InboundItem::create([
            'inbound_id' => $inbound->id,
            'item_id' => $this->variant->id,
            'expected_qty' => $receivedQty,
            'received_qty' => 0,
        ]);

        $inbound = $inbound->fresh('items');

        request()->attributes->set('client_channel', ClientChannelEnum::MOBILE);
        app(InboundService::class)->receive($inbound->id, [
            'received_by' => $this->admin->id,
            'idempotency_key' => (string) Str::uuid(),
            'items' => [[
                'inbound_item_id' => $inbound->items->first()->id,
                'qty' => $receivedQty,
                'condition' => 'GOOD',
            ]],
        ]);

        request()->attributes->set('client_channel', ClientChannelEnum::WEB);

        return $inbound->fresh('items');
    }

    private function onHandAtInboundBin(): int
    {
        return (int) (Inventory::where('item_id', $this->variant->id)
            ->where('location_id', $this->location->id)
            ->where('bin_id', $this->inboundBin->id)
            ->value('on_hand') ?? 0);
    }

    private function correct(Inbound $inbound, int $targetQty, ?string $reason = null): void
    {
        app(InboundService::class)->setReceivedQty(
            $inbound->id,
            $inbound->items->first()->id,
            $targetQty,
            $this->admin->id,
            null,
            $reason,
        );
    }

    public function test_qty_correction_does_not_create_stock_adjustment(): void
    {
        $inbound = $this->makeReceivedInbound(100);

        $this->correct($inbound, 95);

        $this->assertSame(0, StockAdjustment::query()->count());
        $this->assertDatabaseHas('inventory_movements', [
            'transaction_number' => $inbound->transaction_number.'-KOREKSI-QTY',
            'source' => 'INBOUND_QTY_CORRECTION',
            'qty' => -5,
        ]);
    }

    public function test_qty_correction_auto_generates_remarks(): void
    {
        $inbound = $this->makeReceivedInbound(100);

        $this->correct($inbound, 95);

        $receipt = InboundReceipt::where('condition', 'ADJUSTMENT')->latest('created_at')->first();

        $this->assertNotNull($receipt);
        $this->assertStringContainsString($inbound->transaction_number, $receipt->notes);
        $this->assertStringContainsString('V-QC', $receipt->notes, 'SKU harus ikut tercatat');
        $this->assertStringContainsString('100', $receipt->notes);
        $this->assertStringContainsString('95', $receipt->notes);
        $this->assertStringContainsString('-5', $receipt->notes);
        $this->assertStringContainsString('ADMIN QC', $receipt->notes, 'pelaku harus ikut tercatat');
    }

    public function test_qty_correction_auto_remarks_marks_upward_with_plus(): void
    {
        $inbound = $this->makeReceivedInbound(100);

        $this->correct($inbound, 105);

        $receipt = InboundReceipt::where('condition', 'ADJUSTMENT')->latest('created_at')->first();

        $this->assertStringContainsString('+5', $receipt->notes);
    }

    public function test_qty_correction_uses_explicit_reason_when_given(): void
    {
        $inbound = $this->makeReceivedInbound(100);

        $this->correct($inbound, 95, 'barang rusak saat bongkar muat');

        $receipt = InboundReceipt::where('condition', 'ADJUSTMENT')->latest('created_at')->first();

        $this->assertEquals('barang rusak saat bongkar muat', $receipt->notes);
    }

    public function test_qty_correction_lowers_received_qty(): void
    {
        $inbound = $this->makeReceivedInbound(100);

        $this->correct($inbound, 95);

        $this->assertEquals(95, (int) $inbound->fresh('items')->items->first()->received_qty);
    }

    public function test_qty_correction_keeps_receipt_row_without_adjustment_link(): void
    {
        $inbound = $this->makeReceivedInbound(100);

        $this->correct($inbound, 95);

        $receipt = InboundReceipt::where('condition', 'ADJUSTMENT')->latest('created_at')->first();

        $this->assertNotNull($receipt);
        $this->assertNull($receipt->stock_adjustment_id);
        $this->assertEquals(-5, (int) $receipt->qty);
    }

    public function test_qty_correction_applies_stock_delta_exactly_once(): void
    {
        $inbound = $this->makeReceivedInbound(100);
        $this->assertEquals(100, $this->onHandAtInboundBin(), 'prasyarat: 100 masuk Bin Inbound');

        $this->correct($inbound, 95);

        $this->assertEquals(
            95,
            $this->onHandAtInboundBin(),
            'on_hand harus turun tepat 5 — bukan 10 (double-apply) atau 100 (tidak diterapkan)',
        );
    }

    public function test_qty_correction_writes_hidden_correction_movement_not_adjustment(): void
    {
        $inbound = $this->makeReceivedInbound(100);

        $this->correct($inbound, 95);

        $adjustmentMovements = InventoryMovement::where('transaction_number', $inbound->transaction_number.'-KOREKSI-QTY')
            ->where('source', 'INBOUND_QTY_CORRECTION')
            ->get();

        $this->assertCount(1, $adjustmentMovements, 'tepat satu movement koreksi inbound');
        $this->assertEquals(-5, (int) $adjustmentMovements->first()->qty);

        $this->assertEquals(
            0,
            InventoryMovement::where('transaction_number', 'like', '%-EDIT-QTY')->count(),
            'jalur lama -EDIT-QTY harus sudah tidak dipakai',
        );
    }

    public function test_qty_correction_upward_creates_positive_correction_movement(): void
    {
        $inbound = $this->makeReceivedInbound(100);

        $this->correct($inbound, 105, 'fisik ternyata lebih 5 pcs dari catatan');

        $this->assertDatabaseHas('inventory_movements', [
            'transaction_number' => $inbound->transaction_number.'-KOREKSI-QTY',
            'source' => 'INBOUND_QTY_CORRECTION',
            'qty' => 5,
        ]);
        $this->assertEquals(105, $this->onHandAtInboundBin());
    }

    public function test_qty_correction_is_visible_in_stock_history_with_corrected_balance(): void
    {
        $inbound = $this->makeReceivedInbound(100);

        $this->correct($inbound, 95);

        request()->merge([
            'filter' => ['item_id' => $this->variant->id],
            'view' => 'all',
            'per_page' => 50,
        ]);

        $rows = collect(
            app(InventoryMovementRepository::class)->getHistoryPaginated(50)->items()
        );

        $receipt = $rows->firstWhere('source', 'PURCHASE');
        $correction = $rows->firstWhere('source', 'INBOUND_QTY_CORRECTION');

        $this->assertNotNull($receipt, 'Penerimaan awal harus tetap menjadi jejak audit');
        $this->assertNotNull($correction, 'Koreksi qty harus tampil di riwayat stok');
        $this->assertSame(100, (int) $receipt->qty);
        $this->assertSame(-5, (int) $correction->qty);
        $this->assertSame(95, (int) $correction->physical_total_balance);
        $this->assertSame(95, (int) $correction->pending_placement_balance);
    }

    public function test_qty_correction_rejects_below_already_placed(): void
    {
        $inbound = $this->makeReceivedInbound(100);

        $inbound->items->first()->update(['putaway_qty' => 90]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/tidak bisa di bawah yang sudah ditempatkan/');

        $this->correct($inbound, 80);
    }

    public function test_generic_stock_adjustment_still_rejects_inbound_bin(): void
    {
        $this->expectException(UserFacingException::class);
        $this->expectExceptionMessage('bin inbound/DEFAULT');

        app(InventoryService::class)->adjust([
            'item_id' => $this->variant->id,
            'location_id' => $this->location->id,
            'bin_id' => $this->inboundBin->id,
            'qty' => 1,
            'created_by' => $this->admin->id,
        ]);
    }
}
