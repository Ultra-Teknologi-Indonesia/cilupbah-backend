<?php

namespace Modules\Outbound\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Outbound\Models\Packlist;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Models\PicklistItem;
use Modules\Outbound\Models\Shipment;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderItem;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class PickingBoardListPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_ready_to_process_returns_only_the_lightweight_board_contract(): void
    {
        $user = $this->createPrivilegedUser();
        $order = SalesOrder::factory()->create([
            'status' => 'reserved',
            'handed_to_warehouse_at' => now(),
            'shipping_full_name' => 'Board Customer',
        ]);

        $item = SalesOrderItem::create([
            'order_id' => $order->id,
            'sku' => 'BOARD-SKU',
            'description' => 'Board item',
            'qty_in_base' => 2,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/outbound/orders/ready-to-process?per_page=20');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.items.0.id', $item->id)
            ->assertJsonPath('data.0.items.0.qty_in_base', 2)
            ->assertJsonMissingPath('data.0.finance')
            ->assertJsonMissingPath('data.0.shipping')
            ->assertJsonMissingPath('data.0.location')
            ->assertJsonMissingPath('data.0.items.0.item_id')
            ->assertJsonMissingPath('data.0.items.0.image_url');
    }

    public function test_picklist_index_returns_only_the_table_contract(): void
    {
        $user = $this->createPrivilegedUser();
        $location = Location::factory()->create();
        $picklist = Picklist::create([
            'picklist_no' => 'PICK-LIST-CONTRACT',
            'location_id' => $location->id,
            'status' => Picklist::STATUS_DRAFT,
            'created_by' => $user->id,
            'notes' => 'Internal note must not be sent to the table',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/outbound/picklists?per_page=20');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $picklist->id)
            ->assertJsonPath('data.0.picklist_no', 'PICK-LIST-CONTRACT')
            ->assertJsonPath('data.0.items_count', 0)
            ->assertJsonMissingPath('data.0.notes')
            ->assertJsonMissingPath('data.0.created_by')
            ->assertJsonMissingPath('data.0.updated_at');
    }

    public function test_finish_pick_returns_picklist_and_invoice_references_without_full_order_data(): void
    {
        $user = $this->createPrivilegedUser();
        $location = Location::factory()->create();
        $order = SalesOrder::factory()->create([
            'status' => 'picked',
            'location_id' => $location->id,
            'shipping_full_name' => 'Finished Customer',
        ]);
        $variantId = $this->seedProductVariant('FINISHED-SKU');
        $orderItem = SalesOrderItem::create([
            'order_id' => $order->id,
            'item_id' => $variantId,
            'sku' => 'FINISHED-SKU',
            'description' => 'Finished item',
            'qty_in_base' => 1,
        ]);
        $picklist = Picklist::create([
            'picklist_no' => 'PICK-FINISHED-CONTRACT',
            'location_id' => $location->id,
            'status' => Picklist::STATUS_COMPLETED,
            'picker_id' => $user->id,
            'created_by' => $user->id,
            'completed_at' => now(),
        ]);
        PicklistItem::create([
            'picklist_id' => $picklist->id,
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'item_id' => $variantId,
            'sku' => $orderItem->sku,
            'qty_ordered' => 1,
            'qty_picked' => 1,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/outbound/orders/finish-pick?per_page=20');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.picklist_id', $picklist->id)
            ->assertJsonPath('data.0.picklist_no', 'PICK-FINISHED-CONTRACT')
            ->assertJsonMissingPath('data.0.finance')
            ->assertJsonMissingPath('data.0.shipping')
            ->assertJsonMissingPath('data.0.items.0.image_url');
    }

    public function test_packlist_index_returns_only_the_table_contract(): void
    {
        $user = $this->createPrivilegedUser();
        $location = Location::factory()->create();
        $order = SalesOrder::factory()->create([
            'location_id' => $location->id,
            'status' => 'picked',
        ]);
        $packlist = Packlist::create([
            'packlist_no' => 'PACK-LIST-CONTRACT',
            'location_id' => $location->id,
            'order_id' => $order->id,
            'status' => Packlist::STATUS_DRAFT,
            'created_by' => $user->email,
            'notes' => 'Internal note must not be sent to the table',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/outbound/packlists?per_page=20');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $packlist->id)
            ->assertJsonPath('data.0.packlist_no', 'PACK-LIST-CONTRACT')
            ->assertJsonPath('data.0.order.salesorder_no', $order->salesorder_no)
            ->assertJsonMissingPath('data.0.notes')
            ->assertJsonMissingPath('data.0.created_by')
            ->assertJsonMissingPath('data.0.updated_at');
    }

    public function test_shipment_index_returns_only_the_table_contract(): void
    {
        $user = $this->createPrivilegedUser();
        $location = Location::factory()->create();
        $shipment = Shipment::create([
            'shipment_no' => 'SHIPMENT-LIST-CONTRACT',
            'location_id' => $location->id,
            'courier_name' => 'Courier',
            'courier_code' => 'courier',
            'shipment_type' => 'REGULAR',
            'shipment_date' => now()->toDateString(),
            'status' => Shipment::STATUS_SCHEDULED,
            'created_by' => $user->email,
            'notes' => 'Internal note must not be sent to the table',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/outbound/shipments?per_page=20');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $shipment->id)
            ->assertJsonPath('data.0.shipment_no', 'SHIPMENT-LIST-CONTRACT')
            ->assertJsonMissingPath('data.0.notes')
            ->assertJsonMissingPath('data.0.created_by')
            ->assertJsonMissingPath('data.0.driver_id_card_url')
            ->assertJsonMissingPath('data.0.orders');
    }

    private function seedProductVariant(string $sku): string
    {
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Umum']);

        $productId = Str::uuid()->toString();
        DB::table('products')->insert([
            'id' => $productId,
            'name' => 'Prod-'.$sku,
            'category_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $variantId = Str::uuid()->toString();
        DB::table('product_variants')->insert([
            'id' => $variantId,
            'product_id' => $productId,
            'sku' => $sku,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $variantId;
    }
}
