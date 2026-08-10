<?php

namespace Modules\Outbound\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Outbound\Contracts\DriverCallResult;
use Modules\Outbound\Services\OutboundFulfillmentService;
use Tests\TestCase;

class InstantDriverCallServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): OutboundFulfillmentService
    {
        return app(OutboundFulfillmentService::class);
    }

    public function test_resolve_order_ids_dedups_explicit_ids(): void
    {
        $ids = $this->service()->resolveDriverCallOrderIds(['a', 'b', 'a'], []);

        $this->assertSame(['a', 'b'], $ids);
    }

    public function test_dispatch_marks_unknown_orders_as_failed_without_calling_gateway(): void
    {

        $missing = [
            '00000000-0000-7000-8000-000000000001',
            '00000000-0000-7000-8000-000000000002',
        ];

        $result = $this->service()->dispatchInstantDriverCalls($missing, 1);

        $this->assertInstanceOf(DriverCallResult::class, $result);
        $this->assertCount(2, $result->results);
        foreach ($result->results as $r) {
            $this->assertSame(DriverCallResult::STATUS_FAILED, $r['status']);
            $this->assertSame('Pesanan tidak ditemukan.', $r['message']);
        }

        $this->assertCount(2, $result->summary()['failed']);
    }
}
