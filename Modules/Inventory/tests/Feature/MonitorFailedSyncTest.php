<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Repositories\MonitorStockRepository;
use Tests\TestCase;

class MonitorFailedSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_sync_listing_does_not_blow_up(): void
    {
        $result = app(MonitorStockRepository::class)->failedSync(10);

        $this->assertSame(0, $result->total());
    }

    public function test_failed_sync_accepts_channel_shop_filter(): void
    {
        request()->merge(['filter' => ['channel_shop_id' => (string) \Illuminate\Support\Str::uuid()]]);

        $result = app(MonitorStockRepository::class)->failedSync(10);

        $this->assertSame(0, $result->total());
    }
}
