<?php

namespace Tests\Feature;

use App\Models\TrackingItem;
use App\Services\TrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrackingServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        TrackingItem::query()->delete();
    }

    private function make(array $attrs = []): TrackingItem
    {
        $this->seq++;

        return TrackingItem::create(array_merge([
            'domain' => 'sales',
            'method' => 'GET',
            'endpoint' => '/x/'.$this->seq,
            'function_id' => 'fx'.$this->seq,
            'status' => 'todo',
            'source' => 'legacy',
            'pic' => 'Darriel',
        ], $attrs));
    }

    private function service(): TrackingService
    {
        return app(TrackingService::class);
    }

    public function test_list_orders_by_source_priority(): void
    {
        $this->make(['source' => 'omnichannel']);
        $this->make(['source' => 'legacy']);
        $this->make(['source' => 'epic']);

        $sources = $this->service()->list([])->pluck('source')->all();

        $this->assertSame(['legacy', 'epic', 'omnichannel'], $sources);
    }

    public function test_summary_counts_by_status(): void
    {
        $this->make(['status' => 'done']);
        $this->make(['status' => 'todo']);
        $this->make(['status' => 'todo']);

        $summary = $this->service()->summary();

        $this->assertSame(3, $summary['overall']['total']);
        $this->assertSame(1, $summary['overall']['done']);
        $this->assertSame(2, $summary['overall']['todo']);
    }

    public function test_update_persists_and_returns_fresh(): void
    {
        $item = $this->make(['status' => 'todo']);

        $updated = $this->service()->update($item, ['status' => 'done', 'pic' => 'Rasyid']);

        $this->assertSame('done', $updated->status);
        $this->assertSame('Rasyid', $updated->pic);
        $this->assertDatabaseHas('tracking_items', ['id' => $item->id, 'status' => 'done', 'pic' => 'Rasyid']);
    }

    public function test_list_filters_by_domain(): void
    {
        $this->make(['domain' => 'sales']);
        $this->make(['domain' => 'inventory']);

        $items = $this->service()->list(['domain' => 'sales']);

        $this->assertCount(1, $items);
        $this->assertSame('sales', $items->first()->domain);
    }
}
