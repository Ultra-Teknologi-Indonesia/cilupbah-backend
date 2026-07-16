<?php

namespace Modules\Inbound\Tests\Feature;

use App\Enums\ClientChannelEnum;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Inbound\Models\Inbound;
use Modules\Inbound\Models\InboundItem;
use Modules\Inbound\Models\InboundReceipt;
use Modules\Inbound\Services\InboundService;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

class ReceiptAuditTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;
    private LocationBin $inboundBin;
    private ProductVariant $variant;
    private User $staffA;
    private User $staffB;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RoleSeeder::class);

        $this->location = Location::create([
            'location_code' => 'WH-RA', 'location_name' => 'Gudang RA',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);
        $this->inboundBin = LocationBin::create([
            'location_id' => $this->location->id, 'bin_code' => 'IN', 'bin_final_code' => 'WH-RA-IN',
            'is_inbound' => true,
        ]);

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Cat RA', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId, 'name' => 'P-RA', 'sku' => 'P-RA', 'is_active' => true,
        ]);
        $this->variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'V-RA']);

        $this->staffA = User::factory()->create(['name' => 'Staff A']);
        $this->staffB = User::factory()->create(['name' => 'Staff B']);
    }

    private function makeInbound(int $expected = 100): Inbound
    {
        $inbound = Inbound::create([
            'location_id' => $this->location->id,
            'transaction_number' => 'INB-' . fake()->unique()->numerify('########'),
            'type' => Inbound::TYPE_PURCHASE_ORDER,
            'source_type' => 'purchase_order',
            'status' => Inbound::STATUS_DRAFT,
            'expected_date' => now(),
            'created_by' => 'admin',
        ]);

        InboundItem::create([
            'inbound_id' => $inbound->id,
            'item_id' => $this->variant->id,
            'expected_qty' => $expected,
            'received_qty' => 0,
        ]);

        return $inbound->fresh('items');
    }

    private function receiveAs(User $user, Inbound $inbound, int $qty): void
    {
        request()->attributes->set('client_channel', ClientChannelEnum::MOBILE);
        $this->actingAs($user);
        app(InboundService::class)->receive($inbound->id, [
            'received_by' => $user->id,
            'items' => [[
                'inbound_item_id' => $inbound->items->first()->id,
                'qty' => $qty,
                'condition' => 'GOOD',
            ]],
        ]);
    }

    public function test_same_staff_multiple_receipts_recorded_as_separate_rows(): void
    {
        $inbound = $this->makeInbound(100);
        $itemId = $inbound->items->first()->id;

        $this->receiveAs($this->staffA, $inbound, 10);
        $this->receiveAs($this->staffA, $inbound, 5);
        $this->receiveAs($this->staffA, $inbound, 5);

        $receipts = InboundReceipt::where('inbound_item_id', $itemId)->orderBy('received_date')->get();

        $this->assertCount(3, $receipts);
        $this->assertSame([10, 5, 5], $receipts->pluck('qty')->all());
        foreach ($receipts as $r) {
            $this->assertSame($this->staffA->id, $r->received_by_user_id);
        }
    }

    public function test_multi_staff_receipts_each_carry_own_user_id(): void
    {
        $inbound = $this->makeInbound(100);
        $itemId = $inbound->items->first()->id;

        $this->receiveAs($this->staffA, $inbound, 10);
        $this->receiveAs($this->staffB, $inbound, 20);

        $receipts = InboundReceipt::where('inbound_item_id', $itemId)->orderBy('received_date')->get();

        $this->assertCount(2, $receipts);
        $this->assertSame($this->staffA->id, $receipts[0]->received_by_user_id);
        $this->assertSame($this->staffB->id, $receipts[1]->received_by_user_id);
    }

    public function test_kronologi_endpoint_returns_receipts_with_user_relation(): void
    {
        $inbound = $this->makeInbound(100);

        $this->receiveAs($this->staffA, $inbound, 8);
        $this->receiveAs($this->staffB, $inbound, 12);

        // Panggil sebagai user web biasa.
        request()->attributes->set('client_channel', ClientChannelEnum::WEB);
        $admin = User::factory()->create();
        $admin->assignRole('owner');

        $res = $this->actingAs($admin)
            ->getJson("/api/v1/inbounds/{$inbound->id}/receipts");

        $res->assertOk();
        $data = $res->json('data');
        $this->assertCount(2, $data);

        $byUser = collect($data)->groupBy('received_by_user_id');
        $this->assertArrayHasKey($this->staffA->id, $byUser->all());
        $this->assertArrayHasKey($this->staffB->id, $byUser->all());
    }

    public function test_kronologi_filter_by_received_by_user_id(): void
    {
        $inbound = $this->makeInbound(100);

        $this->receiveAs($this->staffA, $inbound, 8);
        $this->receiveAs($this->staffB, $inbound, 12);

        request()->attributes->set('client_channel', ClientChannelEnum::WEB);
        $admin = User::factory()->create();
        $admin->assignRole('owner');

        $res = $this->actingAs($admin)
            ->getJson("/api/v1/inbounds/{$inbound->id}/receipts?filter[received_by_user_id]={$this->staffA->id}");

        $res->assertOk();
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($this->staffA->id, $data[0]['received_by_user_id']);
    }

    public function test_inbound_detail_exposes_received_total_and_received_by_me(): void
    {
        $inbound = $this->makeInbound(100);

        $this->receiveAs($this->staffA, $inbound, 15);
        $this->receiveAs($this->staffB, $inbound, 25);

        // A membuka detail → received_by_me = 15, received_total = 40.
        request()->attributes->set('client_channel', ClientChannelEnum::WEB);
        $this->staffA->assignRole('owner');

        $res = $this->actingAs($this->staffA)
            ->getJson("/api/v1/inbounds/{$inbound->id}");

        $res->assertOk();
        $item = $res->json('data.items.0');
        $this->assertSame(40, (int) $item['received_total']);
        $this->assertSame(15, (int) $item['received_by_me']);
        $this->assertSame(40, (int) $res->json('data.received_total'));
        $this->assertSame(15, (int) $res->json('data.received_by_me'));
    }
}
