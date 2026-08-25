<?php

namespace Modules\Outbound\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Outbound\Services\OutboundFulfillmentService;
use Tests\TestCase;

class FulfillmentPriorityOrderingTest extends TestCase
{
    use RefreshDatabase;

    private function seedReadyOrder(string $tag, ?string $provider, ?string $shipBy): void
    {
        $id = Str::uuid()->toString();
        DB::table('sales_orders')->insert([
            'id' => $id,
            'salesorder_no' => $tag,
            'customer_name' => 'Buyer',
            'source' => 'shopee',
            'status' => 'reserved',
            'handed_to_warehouse_at' => now()->subMinutes(5),
            'shipping_provider' => $provider,
            'ship_by_date' => $shipBy,
            'transaction_date' => now()->subDay(),
            'created_at' => now()->subDay(), 'updated_at' => now(),
        ]);
    }

    public function test_instant_orders_float_to_top_then_nearest_deadline(): void
    {
        $soon = now()->addMinutes(30)->toDateTimeString();
        $far  = now()->addDays(2)->toDateTimeString();

        $this->seedReadyOrder('REG-FAR', 'JNE Reguler', $far);
        $this->seedReadyOrder('INS-FAR', 'Grab Instant', $far);
        $this->seedReadyOrder('REG-SOON', 'JNE Reguler', $soon);
        $this->seedReadyOrder('INS-SOON', 'GoSend Instant', $soon);

        $page = app(OutboundFulfillmentService::class)->getOrdersByStage('ready-to-process', 20);
        $order = collect($page->items())->pluck('salesorder_no')->all();

        $this->assertSame(['INS-SOON', 'INS-FAR', 'REG-SOON', 'REG-FAR'], $order);
    }

    public function test_explicit_sort_from_table_header_overrides_default_priority(): void
    {
        $this->seedReadyOrder('SORT-Z', 'Grab Instant', now()->addMinutes(10)->toDateTimeString());
        $this->seedReadyOrder('SORT-A', 'GoSend Instant', now()->addDays(2)->toDateTimeString());

        request()->replace(['sort' => 'salesorder_no']);

        $page = app(OutboundFulfillmentService::class)->getOrdersByStage('ready-to-process', 20);
        $order = collect($page->items())->pluck('salesorder_no')->all();

        $this->assertSame(['SORT-A', 'SORT-Z'], $order);
    }
}
