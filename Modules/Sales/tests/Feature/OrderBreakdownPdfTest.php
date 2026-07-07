<?php

namespace Modules\Sales\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderItem;
use Tests\TestCase;

class OrderBreakdownPdfTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function makeOrder(array $overrides = []): SalesOrder
    {
        $order = SalesOrder::create(array_merge([
            'salesorder_no'    => 'BRK-' . uniqid(),
            'channel_order_no' => 'CO-' . uniqid(),
            'channel_shop_id'  => 'shop-1',
            'customer_name'    => 'Buyer',
            'source'           => 'shopee',
            'channel_status'   => 'COMPLETED',
            'status'           => 'shipped',
            'sub_total'        => 100000,
            'total_disc'       => 10000,
            'total_tax'        => 0,
            'shipping_cost'    => 15000,
            'insurance_cost'   => 0,
            'grand_total'      => 105000,
            'is_paid'          => true,
        ], $overrides));

        SalesOrderItem::create([
            'order_id'    => $order->id,
            'sku'         => 'SKU-1',
            'description' => 'Produk Uji',
            'qty_in_base' => 1,
            'price'       => 100000,
            'disc'        => 10,
            'disc_amount' => 10000,
            'tax_amount'  => 0,
            'amount'      => 90000,
        ]);

        return $order;
    }

    public function test_breakdown_pdf_streams_successfully_for_settled_marketplace_order(): void
    {
        $order = $this->makeOrder([
            'settlement_amount' => -25600,
            'is_settled'        => true,
            'seller_shipping_borne' => 20600,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->get("/api/v1/sales/{$order->id}/breakdown");

        $response->assertStatus(200);
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    public function test_breakdown_pdf_returns_404_for_unknown_order(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->get('/api/v1/sales/' . \Illuminate\Support\Str::uuid() . '/breakdown');

        $response->assertStatus(404);
    }
}
