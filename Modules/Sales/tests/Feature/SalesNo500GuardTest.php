<?php

namespace Modules\Sales\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesNo500GuardTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createPrivilegedUser();
    }

    public function test_non_uuid_path_ids_return_404_not_500(): void
    {
        $endpoints = [
            ['get', '/api/v1/sales/not-a-uuid'],
            ['put', '/api/v1/sales/not-a-uuid'],
            ['delete', '/api/v1/sales/not-a-uuid'],
            ['get', '/api/v1/sales/payments/not-a-uuid'],
            ['get', '/api/v1/sales/invoices/not-a-uuid'],
            ['get', '/api/v1/sales/settlements/not-a-uuid'],
            ['get', '/api/v1/sales/returns/not-a-uuid'],
            ['post', '/api/v1/sales/returns/not-a-uuid/accept'],
            ['post', '/api/v1/sales/returns/not-a-uuid/reject'],
            ['post', '/api/v1/sales/returns/not-a-uuid/complete'],
            ['get', '/api/v1/sales/return-settlements/invoices/not-a-uuid'],
            ['get', '/api/v1/sales/return-settlements/refunds/not-a-uuid'],
            ['get', '/api/v1/sales/packlists/not-a-uuid'],
            ['get', '/api/v1/sales/picklists/not-a-uuid'],
            ['get', '/api/v1/sales/shipments/not-a-uuid'],
            ['get', '/api/v1/sales/invoices/for-return-wms/not-a-uuid'],
        ];

        foreach ($endpoints as [$method, $uri]) {
            $response = $this->actingAs($this->user, 'sanctum')->json($method, $uri);

            $this->assertSame(
                404,
                $response->getStatusCode(),
                "{$method} {$uri} harus 404, dapat {$response->getStatusCode()}"
            );
        }
    }

    public function test_non_uuid_body_ids_return_422_not_500(): void
    {
        $endpoints = [
            ['/api/v1/sales/orders/set-as-paid', ['order_id' => 'not-a-uuid']],
            ['/api/v1/sales/orders/save-airwaybill', ['order_id' => 'not-a-uuid', 'tracking_number' => 'AWB-1']],
            ['/api/v1/sales/orders/save-received-date', ['order_id' => 'not-a-uuid']],
            ['/api/v1/sales/request-awb-order', ['order_id' => 'not-a-uuid']],
            ['/api/v1/sales/orders/delete-canceled', ['ids' => ['not-a-uuid']]],
            ['/api/v1/sales/orders/mark-as-complete', ['order_ids' => ['not-a-uuid']]],
            ['/api/v1/sales/payments', ['sales_invoice_id' => 'not-a-uuid', 'amount' => 100, 'payment_date' => '2026-06-12', 'payment_method' => 'CASH', 'created_by' => 'tester']],
            ['/api/v1/sales/invoices', ['order_id' => 'not-a-uuid', 'location_id' => 'not-a-uuid']],
            ['/api/v1/sales/returns', ['location_id' => 'not-a-uuid', 'created_by' => 'tester', 'items' => [['item_id' => 'not-a-uuid', 'qty' => 1]]]],
            ['/api/v1/sales/return-settlements/invoices', ['settlement_id' => 'not-a-uuid', 'invoice_id' => 'not-a-uuid']],
        ];

        foreach ($endpoints as [$uri, $payload]) {
            $response = $this->actingAs($this->user, 'sanctum')->postJson($uri, $payload);

            $this->assertSame(
                422,
                $response->getStatusCode(),
                "POST {$uri} harus 422, dapat {$response->getStatusCode()}"
            );
        }

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson('/api/v1/sales/payments', ['id' => 'not-a-uuid'])
            ->assertStatus(422);
    }
}
