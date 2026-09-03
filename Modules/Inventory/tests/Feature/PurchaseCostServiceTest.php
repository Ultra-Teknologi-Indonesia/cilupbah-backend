<?php

declare(strict_types=1);

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Services\PurchaseCostService;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Sales\Events\SalesInvoiceFinalized;
use Modules\Sales\Models\SalesInvoice;
use Modules\Sales\Models\SalesInvoiceItem;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\Concerns\CreatesLegacyNegativeInventory;
use Tests\TestCase;

final class PurchaseCostServiceTest extends TestCase
{
    use CreatesLegacyNegativeInventory;
    use RefreshDatabase;

    public function test_it_returns_the_weighted_average_of_purchase_receipts_only(): void
    {
        [$variant, $location, $bin] = $this->makeContext('PURCHASE-COST-1');

        $this->movement($variant, $location, $bin, 'PURCHASE-A', 'PURCHASE', 2, 1000);
        $this->movement($variant, $location, $bin, 'PURCHASE-B', 'PURCHASE', 1, 4000);
        $this->movement($variant, $location, $bin, 'TRANSFER-C', 'TRANSFER_IN', 100, 9999);

        $cost = app(PurchaseCostService::class)->averageForItem($variant->id);

        $this->assertSame(2000.0, $cost);
    }

    public function test_fallback_valuation_ignores_negative_inventory_rows(): void
    {
        [$variant, $location, $negativeBin] = $this->makeContext('PURCHASE-COST-2');
        $positiveBin = LocationBin::factory()->create([
            'location_id' => $location->id,
            'is_inbound' => false,
        ]);

        $this->createLegacyNegativeInventory([
            'item_id' => $variant->id,
            'location_id' => $location->id,
            'bin_id' => $negativeBin->id,
            'on_hand' => -40,
            'on_order' => 0,
            'available' => -40,
            'avg_cost' => 500,
        ]);
        Inventory::create([
            'item_id' => $variant->id,
            'location_id' => $location->id,
            'bin_id' => $positiveBin->id,
            'on_hand' => 10,
            'on_order' => 0,
            'available' => 10,
            'avg_cost' => 1200,
        ]);

        $cost = app(PurchaseCostService::class)->currentCostForItem($variant->id, $location->id);

        $this->assertSame(1200.0, $cost);
    }

    public function test_invoice_cogs_snapshot_uses_the_purchase_average(): void
    {
        [$variant, $location, $bin] = $this->makeContext('PURCHASE-COST-3');
        $this->movement($variant, $location, $bin, 'PURCHASE-INVOICE-1', 'PURCHASE', 5, 1000);

        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-COST-1',
            'customer_name' => 'Customer',
            'location_id' => $location->id,
            'status' => SalesInvoice::STATUS_OPEN,
            'invoice_date' => now()->toDateString(),
            'total_amount' => 4000,
            'paid_amount' => 0,
            'created_by' => 'test',
        ]);
        $item = SalesInvoiceItem::create([
            'sales_invoice_id' => $invoice->id,
            'item_id' => $variant->id,
            'qty' => 2,
            'unit_price' => 2000,
            'subtotal' => 4000,
        ]);

        SalesInvoiceFinalized::dispatch($invoice->refresh());

        $this->assertSame(1000.0, (float) $item->fresh()->cogs_per_unit);
        $this->assertSame(2000.0, (float) $item->fresh()->total_cogs);
    }

    private function makeContext(string $sku): array
    {
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Purchase Cost '.$sku,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId,
            'name' => 'Product '.$sku,
            'status' => 'master',
            'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => $sku,
            'is_active' => true,
        ]);
        $location = Location::factory()->create();
        $bin = LocationBin::factory()->create([
            'location_id' => $location->id,
            'is_inbound' => false,
        ]);

        return [$variant, $location, $bin];
    }

    private function movement(
        ProductVariant $variant,
        Location $location,
        LocationBin $bin,
        string $transaction,
        string $source,
        int $qty,
        float $cost,
    ): void {
        InventoryMovement::create([
            'item_id' => $variant->id,
            'location_id' => $location->id,
            'bin_id' => $bin->id,
            'transaction_number' => $transaction,
            'source' => $source,
            'qty' => $qty,
            'balance' => $qty,
            'cost_per_unit' => $cost,
            'total_cost' => $qty * $cost,
            'transaction_date' => now(),
            'created_by' => 'test',
        ]);
    }
}
