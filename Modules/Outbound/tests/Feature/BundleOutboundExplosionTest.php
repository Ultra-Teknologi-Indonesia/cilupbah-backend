<?php

namespace Modules\Outbound\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Outbound\Exceptions\OutboundValidationException;
use Modules\Outbound\Models\PacklistItem;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Models\PicklistItem;
use Modules\Outbound\Services\OutboundFulfillmentService;
use Modules\Outbound\Services\PacklistService;
use Modules\Outbound\Services\PicklistService;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderItem;
use Tests\TestCase;

class BundleOutboundExplosionTest extends TestCase
{
    use RefreshDatabase;

    private string $locationId;

    private string $actorId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Umum']);
        $this->locationId = $this->makeLocation();
        $this->actorId = User::factory()->create()->id;
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
            'created_by' => $this->actorId,
        ]);

        $this->assertFalse($picklist->relationLoaded('items'));

        $items = PicklistItem::where('picklist_id', $picklist->id)->get();

        $this->assertCount(2, $items);
        $this->assertSame(4, (int) $items->firstWhere('item_id', $a->id)->qty_ordered);
        $this->assertSame(6, (int) $items->firstWhere('item_id', $b->id)->qty_ordered);
        $this->assertNull($items->firstWhere('item_id', $bundleVar->id));
    }

    public function test_packlist_explodes_bundle_into_component_lines(): void
    {
        [$a, $b, $bundleVar] = $this->makeBundle();
        $order = $this->makeOrder($bundleVar, 2, 'picked');

        $packlist = app(PacklistService::class)->create([
            'order_id' => $order->id,
            'location_id' => $this->locationId,
            'created_by' => $this->actorId,
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
            'created_by' => $this->actorId,
        ]);
        $pickItems = PicklistItem::where('picklist_id', $picklist->id)->get();
        $this->assertCount(1, $pickItems);
        $this->assertSame($single->id, $pickItems->first()->item_id);
        $this->assertSame(3, (int) $pickItems->first()->qty_ordered);

        $order->update(['status' => 'picked']);
        $packlist = app(PacklistService::class)->create([
            'order_id' => $order->id,
            'location_id' => $this->locationId,
            'created_by' => $this->actorId,
        ]);
        $packItems = PacklistItem::where('packlist_id', $packlist->id)->get();
        $this->assertCount(1, $packItems);
        $this->assertSame($single->id, $packItems->first()->item_id);
        $this->assertSame(3, (int) $packItems->first()->qty_ordered);
    }

    public function test_fulfillment_totals_and_item_metadata_use_bundle_components(): void
    {
        [, , $bundleVar] = $this->makeBundle();
        $order = $this->makeOrder($bundleVar, 1, 'reserved');
        $order->update(['handed_to_warehouse_at' => now()]);

        $page = app(OutboundFulfillmentService::class)->getOrdersByStage(
            'ready-to-process',
            10,
            $this->locationId,
        );
        $result = $page->getCollection()->firstWhere('id', $order->id);

        $this->assertNotNull($result);
        $this->assertSame(2, (int) $result->total_sku);
        $this->assertSame(5, (int) $result->total_qty);
        $this->assertCount(2, $result->items->firstOrFail()->getAttribute('bundle_components'));
    }

    public function test_reserved_order_cannot_be_added_to_another_active_picklist(): void
    {
        $single = $this->variant('SINGLE-DUPLICATE');
        $order = $this->makeOrder($single, 1, 'reserved');

        app(PicklistService::class)->create([
            'order_ids' => [$order->id],
            'location_id' => $this->locationId,
            'created_by' => $this->actorId,
        ]);

        try {
            app(PicklistService::class)->create([
                'order_ids' => [$order->id],
                'location_id' => $this->locationId,
                'created_by' => $this->actorId,
            ]);

            $this->fail('Order yang sama seharusnya ditolak pada picklist aktif.');
        } catch (OutboundValidationException $exception) {
            $this->assertStringContainsString('Order sudah terdaftar di picklist', $exception->getMessage());
        }

        $this->assertSame(1, DB::table('picklists')->count());
    }

    public function test_scan_path_cannot_put_one_order_in_a_second_picklist(): void
    {
        $single = $this->variant('SINGLE-SCAN-DUPLICATE');
        $order = $this->makeOrder($single, 1, 'reserved');

        $service = app(OutboundFulfillmentService::class);
        $service->moveToReadyToPick($order->id, $this->locationId, $this->actorId);

        try {
            $service->moveToReadyToPick($order->id, $this->locationId, $this->actorId);
            $this->fail('Order yang sama seharusnya ditolak pada jalur scan picklist.');
        } catch (OutboundValidationException $exception) {
            $this->assertStringContainsString('sudah terdaftar di picklist', $exception->getMessage());
        }

        $this->assertSame(1, DB::table('picklists')->count());
        $this->assertSame(1, DB::table('picklist_items')->where('order_id', $order->id)->count());
    }

    public function test_database_rejects_direct_insert_of_order_into_second_picklist(): void
    {
        $single = $this->variant('SINGLE-DATABASE-DUPLICATE');
        $order = $this->makeOrder($single, 1, 'reserved');
        $first = app(PicklistService::class)->create([
            'order_ids' => [$order->id],
            'location_id' => $this->locationId,
            'created_by' => $this->actorId,
        ]);
        $second = Picklist::create([
            'picklist_no' => 'PICK-DATABASE-DUPLICATE',
            'location_id' => $this->locationId,
            'status' => Picklist::STATUS_DRAFT,
            'created_by' => $this->actorId,
        ]);
        $orderItem = $order->items()->firstOrFail();

        try {
            DB::transaction(function () use ($second, $order, $orderItem, $single): void {
                DB::table('picklist_items')->insert([
                    'id' => Str::uuid()->toString(),
                    'picklist_id' => $second->id,
                    'order_id' => $order->id,
                    'order_item_id' => $orderItem->id,
                    'item_id' => $single->id,
                    'sku' => $single->sku,
                    'qty_ordered' => 1,
                    'qty_picked' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            $this->fail('Database seharusnya menolak order pada picklist kedua.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', $exception->errorInfo[0] ?? null);
        }

        $this->assertSame(1, DB::table('picklist_items')->where('order_id', $order->id)->count());
        $this->assertSame($first->id, DB::table('picklist_items')->where('order_id', $order->id)->value('picklist_id'));
    }
}
