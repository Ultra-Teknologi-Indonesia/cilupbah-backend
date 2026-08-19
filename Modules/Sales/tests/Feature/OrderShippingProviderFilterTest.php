<?php

namespace Modules\Sales\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sales\Models\SalesOrder;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class OrderShippingProviderFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $permId = (string) \Illuminate\Support\Str::uuid();
        \Illuminate\Support\Facades\DB::table('permissions')->insert([
            'id'         => $permId,
            'name'       => 'view-pesanan',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->user->givePermissionTo('view-pesanan');
    }

    private function makeOrder(array $overrides = []): SalesOrder
    {
        return SalesOrder::create(array_merge([
            'salesorder_no'     => 'SO-' . uniqid(),
            'channel_order_no'  => 'CO-' . uniqid(),
            'channel_shop_id'   => 'shop-1',
            'customer_name'     => 'Customer Test',
            'source'            => 'shopee',
            'channel_status'    => 'READY_TO_SHIP',
            'status'            => 'reserved',
            'shipping_provider' => 'SPX Hemat',
            'sub_total'         => 50000,
            'total_disc'        => 0,
            'total_tax'         => 0,
            'shipping_cost'     => 10000,
            'insurance_cost'    => 0,
            'grand_total'       => 60000,
            'is_paid'           => true,
            'transaction_date'  => now(),
        ], $overrides));
    }

    public function test_filter_by_single_shipping_provider(): void
    {
        $this->actingAs($this->user);

        $order1 = $this->makeOrder(['shipping_provider' => 'SPX Hemat']);
        $this->makeOrder(['shipping_provider' => 'J&T Express']);

        $res = $this->getJson('/api/v1/sales?filter[shipping_provider]=SPX Hemat');

        $res->assertOk();
        $this->assertEquals(1, $res->json('meta.total'));
        $this->assertEquals($order1->id, $res->json('data.0.id'));
    }

    public function test_filter_by_multiple_shipping_providers_comma_separated(): void
    {
        $this->actingAs($this->user);

        $this->makeOrder(['shipping_provider' => 'SPX Hemat']);
        $this->makeOrder(['shipping_provider' => 'J&T Express']);
        $this->makeOrder(['shipping_provider' => 'SiCepat Reguler']);

        $res = $this->getJson('/api/v1/sales?filter[shipping_provider]=SPX Hemat,J&T Express');

        $res->assertOk();
        $this->assertEquals(2, $res->json('meta.total'));
    }

    public function test_filter_by_partial_keyword_instan(): void
    {
        $this->actingAs($this->user);

        $this->makeOrder(['shipping_provider' => 'SPX Instant', 'resolved_shipment_type' => 'INSTANT']);
        $this->makeOrder(['shipping_provider' => 'GrabExpress Instant', 'resolved_shipment_type' => 'INSTANT']);
        $this->makeOrder(['shipping_provider' => 'SPX Standard', 'resolved_shipment_type' => 'REGULAR']);

        $res = $this->getJson('/api/v1/sales?filter[shipping_provider]=instan');

        $res->assertOk();
        $this->assertEquals(2, $res->json('meta.total'));
    }

    public function test_jubelio_style_couriers_array_parameter(): void
    {
        $this->actingAs($this->user);

        $this->makeOrder(['shipping_provider' => 'SPX Instant']);
        $this->makeOrder(['shipping_provider' => 'SPX Hemat']);
        $this->makeOrder(['shipping_provider' => 'SiCepat Reguler']);

        $res = $this->getJson('/api/v1/sales?couriers[0]=instan&couriers[1]=hemat');

        $res->assertOk();
        $this->assertEquals(2, $res->json('meta.total'));
    }

    public function test_get_shipping_providers_endpoint_returns_counts_per_provider(): void
    {
        $this->actingAs($this->user);

        $this->makeOrder(['shipping_provider' => 'SPX Hemat', 'status' => 'reserved']);
        $this->makeOrder(['shipping_provider' => 'SPX Hemat', 'status' => 'reserved']);
        $this->makeOrder(['shipping_provider' => 'J&T Express', 'status' => 'reserved']);
        $this->makeOrder(['shipping_provider' => 'SiCepat Reguler', 'status' => 'shipped', 'received_date' => now()]);

        $res = $this->getJson('/api/v1/sales/shipping-providers?tab=ready-to-process');

        $res->assertOk();
        $data = $res->json('data');

        $this->assertCount(2, $data);
        $this->assertEquals('SPX Hemat', $data[0]['name']);
        $this->assertEquals(2, $data[0]['count']);
        $this->assertEquals('J&T Express', $data[1]['name']);
        $this->assertEquals(1, $data[1]['count']);
    }
}
