<?php

namespace Modules\Inbound\Tests\Feature;

use App\Enums\ClientChannelEnum;
use App\Exceptions\UserFacingException;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
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
            'transaction_number' => 'INB-'.fake()->unique()->numerify('########'),
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
            'idempotency_key' => (string) Str::uuid(),
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

    public function test_same_idempotency_key_creates_exactly_one_receipt_and_one_movement(): void
    {
        $inbound = $this->makeInbound(100);
        $item = $inbound->items->first();
        $key = 'mobile-receive-operation-001';
        $payload = [
            'received_by' => $this->staffA->id,
            'idempotency_key' => $key,
            'items' => [[
                'inbound_item_id' => $item->id,
                'qty' => 10,
                'condition' => 'GOOD',
            ]],
        ];

        request()->attributes->set('client_channel', ClientChannelEnum::MOBILE);
        $this->actingAs($this->staffA);

        app(InboundService::class)->receive($inbound->id, $payload);
        app(InboundService::class)->receive($inbound->id, $payload);

        $receipt = InboundReceipt::where('inbound_item_id', $item->id)
            ->where('idempotency_key', $key)
            ->sole();

        $this->assertSame(10, (int) $item->fresh()->received_qty);
        $this->assertSame(10, (int) DB::table('inventories')
            ->where('item_id', $this->variant->id)
            ->where('location_id', $this->location->id)
            ->where('bin_id', $this->inboundBin->id)
            ->sum('on_hand'));
        $this->assertSame(1, DB::table('inventory_movements')
            ->where('inbound_receipt_id', $receipt->id)
            ->count());
        $this->artisan('inventory:audit-transaction-integrity', ['--since' => 1, '--fail-on-issue' => true])
            ->expectsOutputToContain('AUDIT_RESULT=CONSISTENT')
            ->assertSuccessful();
    }

    public function test_integrity_audit_fails_when_receipt_and_movement_qty_diverge(): void
    {
        $inbound = $this->makeInbound(100);
        $item = $inbound->items->first();
        $key = 'mobile-receive-operation-audit-mismatch';

        request()->attributes->set('client_channel', ClientChannelEnum::MOBILE);
        $this->actingAs($this->staffA);
        app(InboundService::class)->receive($inbound->id, [
            'received_by' => $this->staffA->id,
            'idempotency_key' => $key,
            'items' => [[
                'inbound_item_id' => $item->id,
                'qty' => 10,
                'condition' => 'GOOD',
            ]],
        ]);

        $receiptId = InboundReceipt::where('idempotency_key', $key)->value('id');
        DB::table('inventory_movements')->where('inbound_receipt_id', $receiptId)->update(['qty' => 9]);

        $this->artisan('inventory:audit-transaction-integrity', ['--since' => 1, '--fail-on-issue' => true])
            ->expectsOutputToContain('AUDIT_RESULT=REVIEW_REQUIRED')
            ->assertFailed();
    }

    public function test_integrity_audit_fails_when_inbound_staging_balance_diverges(): void
    {
        $inbound = $this->makeInbound(100);
        $this->receiveAs($this->staffA, $inbound, 10);

        DB::table('inventories')
            ->where('item_id', $this->variant->id)
            ->where('location_id', $this->location->id)
            ->where('bin_id', $this->inboundBin->id)
            ->update(['on_hand' => 9]);

        $this->artisan('inventory:audit-transaction-integrity', ['--since' => 1, '--fail-on-issue' => true])
            ->expectsOutputToContain('INBOUND_STAGING_BALANCE_MISMATCH')
            ->expectsOutputToContain('AUDIT_RESULT=REVIEW_REQUIRED')
            ->assertFailed();
    }

    public function test_integrity_audit_excludes_system_transit_balance_from_inbound_staging(): void
    {
        $transit = Location::create([
            'location_code' => Location::SYSTEM_TRANSIT_CODE,
            'location_name' => 'Transit Sistem',
            'location_type' => 'warehouse',
            'is_warehouse' => true,
            'is_active' => true,
        ]);
        $transitBin = LocationBin::create([
            'location_id' => $transit->id,
            'bin_code' => 'TRANSIT',
            'bin_final_code' => 'TRANSIT',
            'is_inbound' => true,
        ]);

        DB::table('inventories')->insert([
            'id' => (string) Str::uuid(),
            'item_id' => $this->variant->id,
            'location_id' => $transit->id,
            'bin_id' => $transitBin->id,
            'batch_no' => '',
            'serial_no' => '',
            'on_hand' => 7,
            'on_order' => 0,
            'available' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('inventory:audit-transaction-integrity', ['--since' => 1, '--fail-on-issue' => true])
            ->expectsOutputToContain('STAGING_ISSUES              | 0')
            ->expectsOutputToContain('AUDIT_RESULT=CONSISTENT')
            ->assertSuccessful();
    }

    public function test_mobile_receipt_without_idempotency_key_is_rejected_without_stock_change(): void
    {
        $inbound = $this->makeInbound(100);
        $item = $inbound->items->first();

        request()->attributes->set('client_channel', ClientChannelEnum::MOBILE);
        $this->actingAs($this->staffA);

        try {
            app(InboundService::class)->receive($inbound->id, [
                'received_by' => $this->staffA->id,
                'items' => [[
                    'inbound_item_id' => $item->id,
                    'qty' => 10,
                    'condition' => 'GOOD',
                ]],
            ]);
            $this->fail('Penerimaan mobile tanpa idempotency key seharusnya ditolak.');
        } catch (UserFacingException $exception) {
            $this->assertSame(422, $exception->getStatus());
            $this->assertSame('INBOUND_RECEIPT_IDEMPOTENCY_KEY_REQUIRED', $exception->getErrors()['code']);
        }

        $this->assertSame(0, (int) $item->fresh()->received_qty);
        $this->assertSame(0, InboundReceipt::where('inbound_item_id', $item->id)->count());
        $this->assertSame(0, DB::table('inventory_movements')->where('item_id', $this->variant->id)->count());
    }

    public function test_reusing_idempotency_key_with_different_payload_is_rejected(): void
    {
        $inbound = $this->makeInbound(100);
        $item = $inbound->items->first();
        $key = 'mobile-receive-operation-002';

        request()->attributes->set('client_channel', ClientChannelEnum::MOBILE);
        $this->actingAs($this->staffA);

        app(InboundService::class)->receive($inbound->id, [
            'received_by' => $this->staffA->id,
            'idempotency_key' => $key,
            'items' => [[
                'inbound_item_id' => $item->id,
                'qty' => 10,
                'condition' => 'GOOD',
            ]],
        ]);

        try {
            app(InboundService::class)->receive($inbound->id, [
                'received_by' => $this->staffA->id,
                'idempotency_key' => $key,
                'items' => [[
                    'inbound_item_id' => $item->id,
                    'qty' => 11,
                    'condition' => 'GOOD',
                ]],
            ]);
            $this->fail('Idempotency conflict seharusnya ditolak.');
        } catch (UserFacingException $exception) {
            $this->assertSame(409, $exception->getStatus());
            $this->assertSame('INBOUND_RECEIPT_IDEMPOTENCY_CONFLICT', $exception->getErrors()['code']);
        }

        $this->assertSame(10, (int) $item->fresh()->received_qty);
        $this->assertSame(1, InboundReceipt::where('inbound_item_id', $item->id)->count());
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
        $this->assertSame('PARTIAL', $res->json('data.receiving_status'));
        $this->assertSame('NOT_STARTED', $res->json('data.placement_status'));
        $this->assertSame(40, (int) $res->json('data.placement_summary.received_qty'));
        $this->assertSame(0, (int) $res->json('data.placement_summary.putaway_qty'));
        $this->assertSame(40, (int) $res->json('data.placement_summary.pending_qty'));
    }

    public function test_receipt_pdf_prints_expected_received_and_remaining_quantities(): void
    {
        $inbound = $this->makeInbound(10);
        $item = $inbound->items->first();
        $item->update([
            'received_qty' => 6,
            'rejected_qty' => 2,
        ]);

        $html = view('inbound::pdf.receipt', [
            'inbound' => $inbound->fresh([
                'location',
                'items.variant.product',
            ]),
        ])->render();

        $this->assertStringContainsString('>10</td>', $html);
        $this->assertStringContainsString('>6</td>', $html);
        $this->assertStringContainsString('>2</td>', $html);
    }
}
