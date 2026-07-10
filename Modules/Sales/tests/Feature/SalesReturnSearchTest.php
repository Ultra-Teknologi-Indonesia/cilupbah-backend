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
        $this->user = User::factory()->create();
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
    }

    public function test_unprocessed_endpoint_is_searchable_by_tracking(): void
    {
        $id = $this->seedReturn('SP-777', 'JX777888');

        $res = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/sales/returns/unprocessed?search=JX777888')
            ->assertOk();

        $this->assertSame($id, $res->json('data.0.id'));
    }
}
