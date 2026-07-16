<?php

namespace Modules\Inbound\Tests\Feature;

use App\Enums\ClientChannelEnum;
use App\Exceptions\InboundSessionClosedException;
use App\Exceptions\MobileSessionActiveException;
use App\Exceptions\UserFacingException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Inbound\Models\Inbound;
use Modules\Inbound\Models\InboundItem;
use Modules\Inbound\Models\InboundParticipant;
use Modules\Inbound\Services\InboundService;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Tests\TestCase;

class MultiParticipantReceiveTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;
    private LocationBin $inboundBin;
    private ProductVariant $variant;
    private array $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->location = Location::create([
            'location_code' => 'WH-MP', 'location_name' => 'Gudang MP',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);
        $this->inboundBin = LocationBin::create([
            'location_id' => $this->location->id, 'bin_code' => 'IN', 'bin_final_code' => 'WH-MP-IN',
            'is_inbound' => true,
        ]);

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Cat MP', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $product = Product::create([
            'category_id' => $categoryId, 'name' => 'P-MP', 'sku' => 'P-MP', 'is_active' => true,
        ]);
        $this->variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'V-MP']);

        $this->staff = [];
        foreach (['s1', 's2', 's3', 's4'] as $key) {
            $this->staff[$key] = User::factory()->create(['name' => strtoupper($key)]);
        }
    }

    private function makeInbound(int $expected = 20000): Inbound
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

    private function asMobile(): void
    {
        request()->attributes->set('client_channel', ClientChannelEnum::MOBILE);
    }

    private function receive(Inbound $inbound, string $userId, int $qty): Inbound
    {
        $this->asMobile();
        return app(InboundService::class)->receive($inbound->id, [
            'received_by' => $userId,
            'items' => [[
                'inbound_item_id' => $inbound->items->first()->id,
                'qty' => $qty,
                'condition' => 'GOOD',
            ]],
        ]);
    }

    public function test_multi_participant_auto_join_via_receive(): void
    {
        $inbound = $this->makeInbound(20000);

        $this->receive($inbound, $this->staff['s1']->id, 6000);
        $this->receive($inbound, $this->staff['s2']->id, 5000);
        $this->receive($inbound, $this->staff['s3']->id, 5000);
        $this->receive($inbound, $this->staff['s4']->id, 4000);

        $participants = InboundParticipant::where('inbound_id', $inbound->id)->get();
        $this->assertCount(4, $participants);
        $this->assertTrue($participants->every(fn ($p) => $p->status === InboundParticipant::STATUS_ACTIVE));

        $item = $inbound->fresh('items')->items->first();
        $this->assertEquals(20000, $item->received_qty);

        $refreshed = $inbound->fresh();
        $this->assertEquals(Inbound::STATUS_PARTIAL, $refreshed->status, 'Status max PARTIAL selama participant ACTIVE');
        $this->assertNotNull($refreshed->receiving_started_at);
    }

    public function test_over_receipt_allowed_no_cap_F1(): void
    {
        $inbound = $this->makeInbound(100);

        // Staff scan 150 padahal expected 100.
        $this->receive($inbound, $this->staff['s1']->id, 150);

        $item = $inbound->fresh('items')->items->first();
        $this->assertEquals(150, $item->received_qty, 'F1: over-receipt tidak diblok');
    }

    public function test_web_edit_locked_while_participant_active_F2(): void
    {
        $inbound = $this->makeInbound(100);
        $this->receive($inbound, $this->staff['s1']->id, 50);

        request()->attributes->set('client_channel', ClientChannelEnum::WEB);

        $this->expectException(MobileSessionActiveException::class);
        app(InboundService::class)->setReceivedQty(
            $inbound->id,
            $inbound->items->first()->id,
            60,
            $this->staff['s1']->id,
        );
    }

    public function test_admin_close_receiving_finalizes_and_withdraws_active_participants(): void
    {
        $inbound = $this->makeInbound(100);
        $this->receive($inbound, $this->staff['s1']->id, 50);
        $this->receive($inbound, $this->staff['s2']->id, 50);

        $mid = $inbound->fresh();
        $this->assertEquals(Inbound::STATUS_PARTIAL, $mid->status, 'Sebelum admin close: PARTIAL');

        $admin = User::factory()->create(['name' => 'ADMIN']);
        app(InboundService::class)->closeReceiving($inbound->id, $admin->id);

        $done = $inbound->fresh();
        $this->assertEquals(Inbound::STATUS_RECEIVED, $done->status);

        // Semua participant ACTIVE jadi WITHDRAWN dengan reason admin_finalize.
        $participants = InboundParticipant::where('inbound_id', $inbound->id)->get();
        $this->assertCount(2, $participants);
        foreach ($participants as $p) {
            $this->assertEquals(InboundParticipant::STATUS_WITHDRAWN, $p->status);
            $this->assertEquals('admin_finalize', $p->withdraw_reason);
            $this->assertEquals($admin->id, $p->withdrawn_by);
        }

        // Web edit sekarang boleh (session lock lepas).
        request()->attributes->set('client_channel', ClientChannelEnum::WEB);
        app(InboundService::class)->setReceivedQty(
            $inbound->id,
            $inbound->items->first()->id,
            90,
            $this->staff['s1']->id,
        );
        $this->assertEquals(90, $inbound->fresh('items')->items->first()->received_qty);
    }

    public function test_receive_stays_partial_even_when_expected_reached(): void
    {
        // Fase E: receive() TIDAK PERNAH auto-transition ke RECEIVED, walau qty penuh.
        $inbound = $this->makeInbound(100);
        $this->receive($inbound, $this->staff['s1']->id, 100);

        $this->assertEquals(Inbound::STATUS_PARTIAL, $inbound->fresh()->status);
    }

    public function test_late_join_blocked_after_admin_closed_session(): void
    {
        $inbound = $this->makeInbound(100);
        $this->receive($inbound, $this->staff['s1']->id, 100);

        $admin = User::factory()->create(['name' => 'ADMIN']);
        app(InboundService::class)->closeReceiving($inbound->id, $admin->id);

        $this->expectExceptionMessageMatches('/berstatus RECEIVED/');
        $this->receive($inbound->fresh('items'), $this->staff['s2']->id, 5);
    }

    public function test_withdrawn_participant_cannot_rejoin_F3(): void
    {
        $inbound = $this->makeInbound(100);
        $this->receive($inbound, $this->staff['s1']->id, 30);

        $admin = User::factory()->create(['name' => 'ADMIN']);
        app(InboundService::class)->withdrawParticipant(
            $inbound->id,
            $this->staff['s1']->id,
            $admin->id,
            'test withdraw',
        );

        $this->expectException(UserFacingException::class);
        $this->receive($inbound->fresh('items'), $this->staff['s1']->id, 5);
    }

    public function test_cancel_blocked_when_participant_active_F4(): void
    {
        $inbound = $this->makeInbound(100);
        $this->receive($inbound, $this->staff['s1']->id, 30);

        $this->expectException(MobileSessionActiveException::class);
        app(InboundService::class)->cancel($inbound->id, $this->staff['s1']->id);
    }

    public function test_admin_withdraw_participant_only_flips_status(): void
    {
        // Fase E: withdraw seorang staff hanya melepas 1 participant. Status inbound
        // tetap PARTIAL (bukan naik ke RECEIVED walau semua withdraw) — hanya admin
        // closeReceiving yang bisa RECEIVED.
        $inbound = $this->makeInbound(100);
        $this->receive($inbound, $this->staff['s1']->id, 30);

        $admin = User::factory()->create(['name' => 'ADMIN']);
        app(InboundService::class)->withdrawParticipant(
            $inbound->id,
            $this->staff['s1']->id,
            $admin->id,
        );

        $refreshed = $inbound->fresh();
        $this->assertFalse($refreshed->hasActiveParticipant());
        $this->assertEquals(Inbound::STATUS_PARTIAL, $refreshed->status, 'withdraw tidak boleh naikkan status');
        $this->assertNull($refreshed->once_received_at, 'once_received_at hanya set oleh closeReceiving');
    }

    public function test_admin_close_from_draft_without_receive_still_works(): void
    {
        // Admin bisa close inbound DRAFT (tanpa ada scan mobile sama sekali).
        $inbound = $this->makeInbound(100);

        $admin = User::factory()->create(['name' => 'ADMIN']);
        app(InboundService::class)->closeReceiving($inbound->id, $admin->id);

        $this->assertEquals(Inbound::STATUS_RECEIVED, $inbound->fresh()->status);
    }

    public function test_join_session_registers_participant(): void
    {
        $inbound = $this->makeInbound(100);
        app(InboundService::class)->joinSession($inbound->id, $this->staff['s1']->id);

        $this->assertTrue(
            InboundParticipant::where('inbound_id', $inbound->id)
                ->where('user_id', $this->staff['s1']->id)
                ->where('status', InboundParticipant::STATUS_ACTIVE)
                ->exists()
        );
    }
}
