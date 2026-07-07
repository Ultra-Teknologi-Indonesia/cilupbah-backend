<?php

namespace Modules\Outbound\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Outbound\Models\Packlist;
use Modules\Outbound\Models\PacklistItem;
use Modules\Outbound\Models\PicklistItem;
use Modules\Outbound\Services\PacklistService;
use Modules\Outbound\Services\PicklistService;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderItem;
use Tests\TestCase;

/**
 * Bundle tidak punya wujud fisik: picker & packer memegang komponen. Test ini mengunci
 * bahwa PicklistService DAN PacklistService sama-sama meledakkan order item bundle menjadi
 * baris-baris komponen (× qty), dan tidak pernah membuat baris untuk variant bundle itu sendiri.
 */
class BundleOutboundExplosionTest extends TestCase
{
    use RefreshDatabase;

    private string $locationId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Umum']);
        $this->locationId = $this->makeLocation();
    }

    private function makeLocation(): string
    {
        $id = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $id,
            'location_code' => 'LOC-'.substr($id, 0, 6),
            'location_name' => 'Gudang',
            'location_type' => 'WAREHOUSE',
            'is_warehouse' => true,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function variant(string $sku, bool $isBundle = false): ProductVariant
    {
        $product = Product::create([
            'name' => $sku.' product',
            'category_id' => 1,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
            'is_bundle' => $isBundle,
        ]);

        return ProductVariant::create([
            'product_id' => $product->id,
            'sku' => $sku,
            'is_active' => true,
        ]);
    }

    /**
     * @return array{0: ProductVariant, 1: ProductVariant, 2: ProductVariant}
     */
    private function makeBundle(): array
    {
        $a = $this->variant('COMP-A');
        $b = $this->variant('COMP-B');

        $bundleVar = $this->variant('BUNDLE-1', true);
        $bundleVar->product->bundleItems()->create(['component_variant_id' => $a->id, 'qty' => 2]);
        $bundleVar->product->bundleItems()->create(['component_variant_id' => $b->id, 'qty' => 3]);

        return [$a, $b, $bundleVar];
    }

    private function makeOrder(ProductVariant $bundleVar, int $qty, string $status): SalesOrder
    {
        $order = SalesOrder::create([
            'salesorder_no' => 'SO-'.Str::upper(Str::random(6)),
            'customer_name' => 'Buyer',
            'location_id' => $this->locationId,
            'status' => $status,
        ]);

        SalesOrderItem::create([
            'order_id' => $order->id,
            'item_id' => $bundleVar->id,
            'sku' => $bundleVar->sku,
            'qty_in_base' => $qty,
        ]);

        return $order;
    }

    public function test_picklist_explodes_bundle_into_component_lines(): void
    {
        [$a, $b, $bundleVar] = $this->makeBundle();
        $order = $this->makeOrder($bundleVar, 2, 'reserved');

        $picklist = app(PicklistService::class)->create([
            'order_ids' => [$order->id],
            'location_id' => $this->locationId,
            'created_by' => 'system:test',
        ]);

        $items = PicklistItem::where('picklist_id', $picklist->id)->get();

        $this->assertCount(2, $items);
        $this->assertSame(4, (int) $items->firstWhere('item_id', $a->id)->qty_ordered); // 2 × 2
        $this->assertSame(6, (int) $items->firstWhere('item_id', $b->id)->qty_ordered); // 2 × 3
        $this->assertNull($items->firstWhere('item_id', $bundleVar->id));
    }

    public function test_packlist_explodes_bundle_into_component_lines(): void
    {
        [$a, $b, $bundleVar] = $this->makeBundle();
        $order = $this->makeOrder($bundleVar, 2, 'picked');

        $packlist = app(PacklistService::class)->create([
            'order_id' => $order->id,
            'location_id' => $this->locationId,
            'created_by' => 'system:test',
        ]);

        $items = PacklistItem::where('packlist_id', $packlist->id)->get();

        $this->assertCount(2, $items);
        $this->assertSame(4, (int) $items->firstWhere('item_id', $a->id)->qty_ordered);
        $this->assertSame(6, (int) $items->firstWhere('item_id', $b->id)->qty_ordered);
        $this->assertNull($items->firstWhere('item_id', $bundleVar->id));
        $this->assertSame('COMP-A', $items->firstWhere('item_id', $a->id)->sku);
    }

    public function test_non_bundle_order_keeps_single_line_in_both_stages(): void
    {
        $single = $this->variant('SINGLE-1');
        $order = $this->makeOrder($single, 3, 'reserved');

        $picklist = app(PicklistService::class)->create([
            'order_ids' => [$order->id],
            'location_id' => $this->locationId,
            'created_by' => 'system:test',
        ]);
        $pickItems = PicklistItem::where('picklist_id', $picklist->id)->get();
        $this->assertCount(1, $pickItems);
        $this->assertSame($single->id, $pickItems->first()->item_id);
        $this->assertSame(3, (int) $pickItems->first()->qty_ordered);

        $order->update(['status' => 'picked']);
        $packlist = app(PacklistService::class)->create([
            'order_id' => $order->id,
            'location_id' => $this->locationId,
            'created_by' => 'system:test',
        ]);
        $packItems = PacklistItem::where('packlist_id', $packlist->id)->get();
        $this->assertCount(1, $packItems);
        $this->assertSame($single->id, $packItems->first()->item_id);
        $this->assertSame(3, (int) $packItems->first()->qty_ordered);
    }
}
