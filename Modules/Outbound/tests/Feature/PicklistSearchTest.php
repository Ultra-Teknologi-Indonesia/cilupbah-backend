<?php

namespace Modules\Outbound\Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Models\PicklistItem;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

class PicklistSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_search_picklist_by_picklist_no_picker_name_and_order_number(): void
    {
        $user = User::factory()->create();
        $permission = Permission::firstOrCreate(['name' => 'view-picking', 'guard_name' => 'web']);
        $user->givePermissionTo($permission);

        $picker1 = User::factory()->create(['name' => 'Budi Santoso']);
        $picker2 = User::factory()->create(['name' => 'Joko Anwar']);

        $locationId = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $locationId,
            'location_code' => 'WH-SEARCH-TEST',
            'location_name' => 'Gudang Search Test',
            'location_type' => 'WAREHOUSE',
            'is_warehouse' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order1 = SalesOrder::create([
            'salesorder_no' => 'SO-2026-SEARCH-001',
            'channel_order_no' => 'CH-ORDER-ALPHA-999',
            'tracking_number' => 'AWB-ALPHA-888',
            'status' => 'reserved',
            'location_id' => $locationId,
            'customer_name' => 'Customer Satu',
            'created_by' => $user->id,
        ]);

        $order2 = SalesOrder::create([
            'salesorder_no' => 'SO-2026-SEARCH-002',
            'channel_order_no' => 'CH-ORDER-BETA-777',
            'tracking_number' => 'AWB-BETA-666',
            'status' => 'reserved',
            'location_id' => $locationId,
            'customer_name' => 'Customer Dua',
            'created_by' => $user->id,
        ]);

        $variantId1 = $this->seedProductVariant('SKU-001');
        $variantId2 = $this->seedProductVariant('SKU-002');

        $orderItemId1 = Str::uuid()->toString();
        DB::table('sales_order_items')->insert([
            'id' => $orderItemId1,
            'order_id' => $order1->id,
            'item_id' => $variantId1,
            'sku' => 'SKU-001',
            'qty_in_base' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $orderItemId2 = Str::uuid()->toString();
        DB::table('sales_order_items')->insert([
            'id' => $orderItemId2,
            'order_id' => $order2->id,
            'item_id' => $variantId2,
            'sku' => 'SKU-002',
            'qty_in_base' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $picklist1 = Picklist::create([
            'picklist_no' => 'PK-2026-AAA-111',
            'location_id' => $locationId,
            'picker_id' => $picker1->id,
            'status' => Picklist::STATUS_IN_PROGRESS,
            'created_by' => $user->id,
        ]);

        PicklistItem::create([
            'picklist_id' => $picklist1->id,
            'order_id' => $order1->id,
            'order_item_id' => $orderItemId1,
            'item_id' => $variantId1,
            'sku' => 'SKU-001',
            'qty_ordered' => 2,
            'qty_picked' => 0,
            'item_status' => PicklistItem::STATUS_PENDING,
        ]);

        $picklist2 = Picklist::create([
            'picklist_no' => 'PK-2026-BBB-222',
            'location_id' => $locationId,
            'picker_id' => $picker2->id,
            'status' => Picklist::STATUS_IN_PROGRESS,
            'created_by' => $user->id,
        ]);

        PicklistItem::create([
            'picklist_id' => $picklist2->id,
            'order_id' => $order2->id,
            'order_item_id' => $orderItemId2,
            'item_id' => $variantId2,
            'sku' => 'SKU-002',
            'qty_ordered' => 1,
            'qty_picked' => 0,
            'item_status' => PicklistItem::STATUS_PENDING,
        ]);

        // 1. Search by picklist_no
        $res1 = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/outbound/picklists?search=AAA-111');
        $res1->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.picklist_no', 'PK-2026-AAA-111');

        // 2. Search by picker name
        $res2 = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/outbound/picklists?search=Budi');
        $res2->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.picklist_no', 'PK-2026-AAA-111');

        $res3 = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/outbound/picklists?search=Anwar');
        $res3->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.picklist_no', 'PK-2026-BBB-222');

        // 3. Search by salesorder_no
        $res4 = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/outbound/picklists?search=SEARCH-001');
        $res4->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.picklist_no', 'PK-2026-AAA-111');

        // 4. Search by channel_order_no
        $res5 = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/outbound/picklists?search=ALPHA-999');
        $res5->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.picklist_no', 'PK-2026-AAA-111');

        // 5. Search by tracking_number
        $res6 = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/outbound/picklists?search=AWB-BETA');
        $res6->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.picklist_no', 'PK-2026-BBB-222');
    }

    private function seedProductVariant(string $sku): string
    {
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Umum']);

        $productId = Str::uuid()->toString();
        DB::table('products')->insert([
            'id' => $productId,
            'name' => 'Prod-'.$sku,
            'category_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $variantId = Str::uuid()->toString();
        DB::table('product_variants')->insert([
            'id' => $variantId,
            'product_id' => $productId,
            'sku' => $sku,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $variantId;
    }
}
