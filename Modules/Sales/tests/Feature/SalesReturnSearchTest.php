<?php

namespace Modules\Sales\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesReturn;
use Tests\TestCase;

class SalesReturnSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createPrivilegedUser();

        $channelId = Str::uuid()->toString();
        DB::table('channels')->insert([
            'id' => $channelId,
            'code' => 'shopee',
            'name' => 'Shopee',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('channel_shops')->insert([
            'id' => Str::uuid()->toString(),
            'channel_id' => $channelId,
            'shop_id' => 'shop-9',
            'shop_name' => 'Cilupbah Shopee',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedReturn(string $channelOrderNo, string $trackingNumber): string
    {
        $locationId = Str::uuid()->toString();
        DB::table('locations')->insert([
            'id' => $locationId, 'location_code' => 'LOC-' . Str::upper(Str::random(6)),
            'location_name' => 'Gudang S',
            'location_type' => 'WAREHOUSE', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $orderId = Str::uuid()->toString();
        DB::table('sales_orders')->insert([
            'id' => $orderId,
            'salesorder_no' => 'SHOPEE-' . $channelOrderNo,
            'channel_order_no' => $channelOrderNo,
            'customer_name' => 'Budi',
            'source' => 'shopee',
            'location_id' => $locationId,
            'status' => 'shipped',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $return = SalesReturn::create([
            'return_number' => 'RET-' . Str::upper(Str::random(6)),
            'order_id' => $orderId,
            'location_id' => $locationId,
            'source' => SalesReturn::SOURCE_MARKETPLACE,
            'channel_return_id' => 'shopee:RSN-' . Str::upper(Str::random(6)),
            'channel_shop_id' => 'shop-9',
            'customer_name' => 'Budi',
            'status' => SalesReturn::STATUS_PENDING,
            'return_tracking_number' => $trackingNumber,
            'return_carrier' => 'J&T',
            'channel_reason_code' => 'CHANGE_MIND',
            'channel_reason_text' => 'Change of mind',
            'marketplace_decision' => SalesReturn::MP_DECISION_PENDING,
            'marketplace_raw_status' => 'RETURN_OR_REFUND_REQUEST_PENDING',
            'created_by' => 'system:shopee-webhook',
        ]);

        return $return->id;
    }

    public function test_search_by_return_tracking_number_finds_return(): void
    {
        $id = $this->seedReturn('SP-123', 'JX9988776655');
        $this->seedReturn('SP-999', 'OTHER-000');

        $res = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/sales/returns?search=JX9988776655')
            ->assertOk();

        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($id, $data[0]['id']);
    }

    public function test_search_by_channel_order_number_finds_return(): void
    {
        $id = $this->seedReturn('SP-ABC123', 'JX111');

        $res = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/sales/returns?search=SP-ABC123')
            ->assertOk();

        $this->assertSame($id, $res->json('data.0.id'));
        $this->assertSame('Pembeli berubah pikiran', $res->json('data.0.reason_display'));
        $this->assertSame('Menunggu peninjauan channel', $res->json('data.0.marketplace_raw_status_label'));
    }

    public function test_search_matches_return_number_and_return_carrier(): void
    {
        $id = $this->seedReturn('SP-SEARCH', 'JX-SEARCH');

        DB::table('sales_returns')->where('id', $id)->update([
            'return_number' => 'RET-20260824-ZCWQ',
            'return_carrier' => 'SiCepat Ekspres',
        ]);

        foreach (['RET-20260824-ZCWQ', 'SiCepat Ekspres'] as $search) {
            $response = $this->actingAs($this->user, 'sanctum')
                ->getJson('/api/v1/sales/returns?search=' . urlencode($search) . '&limit=200')
                ->assertOk();

            $this->assertSame($id, $response->json('data.0.id'));
            $this->assertSame(200, $response->json('meta.per_page'));
        }
    }

    public function test_filters_by_reason_and_channel_shop(): void
    {
        $id = $this->seedReturn('SP-FILTER', 'JX-FILTER');

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/sales/returns?filter[reason]=CHANGE_MIND&filter[channel_shop_id]=shop-9')
            ->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame($id, $response->json('data.0.id'));
    }

    public function test_filter_options_are_dynamic_across_marketplace_returns(): void
    {
        $this->seedReturn('SP-OPTIONS', 'JX-OPTIONS');

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/sales/returns/filter-options')
            ->assertOk();

        $this->assertSame('CHANGE_MIND', $response->json('data.reasons.0.value'));
        $this->assertSame('shop-9', $response->json('data.shops.0.value'));
    }

    public function test_unprocessed_endpoint_is_searchable_by_tracking(): void
    {
        $id = $this->seedReturn('SP-777', 'JX777888');

        $res = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/sales/returns/unprocessed?search=JX777888')
            ->assertOk();

        $this->assertSame($id, $res->json('data.0.id'));
    }

    public function test_list_uses_per_page_and_server_side_sorting(): void
    {
        $firstId = $this->seedReturn('SP-001', 'JX001');
        $secondId = $this->seedReturn('SP-002', 'JX002');

        DB::table('sales_returns')->where('id', $firstId)->update([
            'return_number' => 'RET-0001',
        ]);
        DB::table('sales_returns')->where('id', $secondId)->update([
            'return_number' => 'RET-0002',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/sales/returns?per_page=1&sort=return_number&filter[status]=PENDING')
            ->assertOk();

        $response->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $firstId);
    }

    public function test_unprocessed_uses_per_page_and_server_side_sorting(): void
    {
        $firstId = $this->seedReturn('SP-101', 'JX101');
        $secondId = $this->seedReturn('SP-102', 'JX102');

        DB::table('sales_returns')->where('id', $firstId)->update([
            'return_number' => 'RET-0101',
        ]);
        DB::table('sales_returns')->where('id', $secondId)->update([
            'return_number' => 'RET-0102',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/sales/returns/unprocessed?per_page=1&sort=-return_number')
            ->assertOk();

        $response->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $secondId);
    }

    public function test_marketplace_decision_keeps_canonical_mp_value(): void
    {
        $return = new SalesReturn;
        $return->marketplace_decision = SalesReturn::MP_DECISION_REFUNDED;

        $this->assertSame(
            SalesReturn::MP_DECISION_REFUNDED,
            $return->getAttributes()['marketplace_decision'],
        );

        $return->marketplace_decision = 'REFUNDED';

        $this->assertSame(
            SalesReturn::MP_DECISION_REFUNDED,
            $return->getAttributes()['marketplace_decision'],
        );
    }

    public function test_channel_statuses_map_to_marketplace_decisions(): void
    {
        $cases = [
            ['tiktok', 'RETURN_OR_REFUND_REQUEST_COMPLETE', SalesReturn::MP_DECISION_REFUNDED],
            ['tiktok', 'BUYER_SHIPPED_ITEM', SalesReturn::MP_DECISION_APPROVED],
            ['tiktok', 'RETURN_OR_REFUND_REQUEST_CANCEL', SalesReturn::MP_DECISION_CLOSED],
            ['tiktok', 'REPLACEMENT_REQUEST_REJECT', SalesReturn::MP_DECISION_REJECTED],
            ['shopee', 'ACCEPTED', SalesReturn::MP_DECISION_APPROVED],
            ['shopee', 'SELLER_DISPUTE', SalesReturn::MP_DECISION_DISPUTE],
            ['shopee', 'JUDGING', SalesReturn::MP_DECISION_JUDGING],
            ['shopee', 'CANCELLED', SalesReturn::MP_DECISION_CLOSED],
        ];

        foreach ($cases as [$channel, $rawStatus, $expected]) {
            $this->assertSame(
                $expected,
                SalesReturn::normalizeMarketplaceDecision($channel, $rawStatus),
                "Mapping gagal untuk {$channel}:{$rawStatus}",
            );
        }
    }

    public function test_older_marketplace_decision_cannot_regress_a_newer_decision(): void
    {
        $this->assertFalse(SalesReturn::shouldApplyMarketplaceDecision(
            SalesReturn::MP_DECISION_REFUNDED,
            SalesReturn::MP_DECISION_PENDING,
        ));

        $this->assertTrue(SalesReturn::shouldApplyMarketplaceDecision(
            SalesReturn::MP_DECISION_CLOSED,
            SalesReturn::MP_DECISION_REFUNDED,
        ));

        $this->assertTrue(SalesReturn::shouldApplyMarketplaceDecision(
            null,
            SalesReturn::MP_DECISION_PENDING,
        ));
    }
}
