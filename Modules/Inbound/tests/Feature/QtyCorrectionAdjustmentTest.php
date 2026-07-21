<?php

namespace Modules\Inbound\Tests\Feature;

use App\Enums\ClientChannelEnum;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inbound\Models\Inbound;
use Modules\Inbound\Models\InboundItem;
use Modules\Inbound\Models\InboundReceipt;
use Modules\Inbound\Services\InboundService;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\StockAdjustment;
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
            'transaction_number' => 'INB-QC-' . fake()->unique()->numerify('######'),
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

    public function test_qty_correction_creates_stock_adjustment(): void
    {
        $inbound = $this->makeReceivedInbound(100);

        $this->correct($inbound, 95);

        $adjustment = StockAdjustment::with('items')->latest('created_at')->first();

        $this->assertNotNull($adjustment, 'Dokumen Penyesuaian Stok harus terbentuk otomatis');
        $this->assertMatchesRegularExpression('/^ADJ-/', $adjustment->adjustment_no);
        $this->assertEquals($this->location->id, $adjustment->location_id);

        $this->assertCount(1, $adjustment->items);
        $item = $adjustment->items->first();
        $this->assertEquals($this->variant->id, $item->item_id);
        $this->assertEquals($this->inboundBin->id, $item->bin_id);
        $this->assertEquals(-5, (int) $item->difference_qty);
    }

    public function test_qty_correction_auto_generates_remarks(): void
    {
        $inbound = $this->makeReceivedInbound(100);

        $this->correct($inbound, 95);

        $adjustment = StockAdjustment::with('items')->latest('created_at')->first();

        foreach ([$adjustment->notes, $adjustment->items->first()->notes] as $notes) {
            $this->assertStringContainsString($inbound->transaction_number, $notes);
            $this->assertStringContainsString('V-QC', $notes, 'SKU harus ikut tercatat');
            $this->assertStringContainsString('100', $notes);
            $this->assertStringContainsString('95', $notes);
            $this->assertStringContainsString('-5', $notes);
            $this->assertStringContainsString('ADMIN QC', $notes, 'pelaku harus ikut tercatat');
        }
    }

    public function test_qty_correction_auto_remarks_marks_upward_with_plus(): void
    {
        $inbound = $this->makeReceivedInbound(100);

        $this->correct($inbound, 105);

        $adjustment = StockAdjustment::latest('created_at')->first();

        $this->assertStringContainsString('+5', $adjustment->notes);
    }

    public function test_qty_correction_uses_explicit_reason_when_given(): void
    {
        $inbound = $this->makeReceivedInbound(100);

        $this->correct($inbound, 95, 'barang rusak saat bongkar muat');

        $adjustment = StockAdjustment::latest('created_at')->first();

        $this->assertEquals('barang rusak saat bongkar muat', $adjustment->notes);
    }

    public function test_qty_correction_lowers_received_qty(): void
    {
        $inbound = $this->makeReceivedInbound(100);

        $this->correct($inbound, 95);

        $this->assertEquals(95, (int) $inbound->fresh('items')->items->first()->received_qty);
    }

    public function test_qty_correction_links_receipt_row_to_adjustment(): void
    {
        $inbound = $this->makeReceivedInbound(100);

        $this->correct($inbound, 95);

        $adjustment = StockAdjustment::latest('created_at')->first();
        $receipt = InboundReceipt::where('condition', 'ADJUSTMENT')->latest('created_at')->first();

        $this->assertNotNull($receipt);
        $this->assertEquals($adjustment->id, $receipt->stock_adjustment_id);
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

    public function test_qty_correction_writes_adjustment_movement_not_edit_qty(): void
    {
        $inbound = $this->makeReceivedInbound(100);

        $this->correct($inbound, 95);

        $adjustment = StockAdjustment::latest('created_at')->first();

        $adjustmentMovements = InventoryMovement::where('transaction_number', $adjustment->adjustment_no)
            ->where('source', 'ADJUSTMENT')
            ->get();

        $this->assertCount(1, $adjustmentMovements, 'tepat satu movement ADJUSTMENT');
        $this->assertEquals(-5, (int) $adjustmentMovements->first()->qty);

        $this->assertEquals(
            0,
            InventoryMovement::where('transaction_number', 'like', '%-EDIT-QTY')->count(),
            'jalur lama -EDIT-QTY harus sudah tidak dipakai',
        );
    }

    public function test_qty_correction_upward_creates_positive_adjustment(): void
    {
        $inbound = $this->makeReceivedInbound(100);

        $this->correct($inbound, 105, 'fisik ternyata lebih 5 pcs dari catatan');

        $adjustment = StockAdjustment::with('items')->latest('created_at')->first();

        $this->assertEquals(5, (int) $adjustment->items->first()->difference_qty);
        $this->assertEquals(105, $this->onHandAtInboundBin());
    }

    public function test_qty_correction_rejects_below_already_placed(): void
    {
        $inbound = $this->makeReceivedInbound(100);

        $inbound->items->first()->update(['putaway_qty' => 90]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/tidak bisa di bawah yang sudah ditempatkan/');

        $this->correct($inbound, 80);
    }
}
