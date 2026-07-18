<?php

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\Inventory\Models\Inventory;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;
use App\Models\User;
use Tests\TestCase;

class MonitorStockTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->actingAs(User::factory()->create(), 'sanctum');

        $this->location = Location::create([
            'location_code' => 'WH-01',
            'location_name' => 'Pusat',
            'location_type' => 'warehouse',
            'is_warehouse'  => true,
            'is_active'     => true,
        ]);

        $this->categoryId = \DB::table('categories')->insertGetId([
            'name' => 'Aksesoris', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeVariant(string $sku, array $variantAttrs, ?array $stock, bool $isStored = true): ProductVariant
    {
        $product = Product::create([
            'category_id' => $this->categoryId,
            'name'        => "Produk {$sku}",
            'sku'         => "P-{$sku}",
            'is_active'   => true,
            'is_stored'   => $isStored,
        ]);

        $variant = ProductVariant::create(array_merge([
            'product_id' => $product->id,
            'sku'        => $sku,
            'sell_price' => 100000,
            'is_active'  => true,
        ], $variantAttrs));

        if ($stock !== null) {
            Inventory::create(array_merge([
                'item_id'     => $variant->id,
                'location_id' => $this->location->id,
                'bin_id'      => null,
                'batch_no'    => '',
                'serial_no'   => '',
                'on_hand'     => 0,
                'on_order'    => 0,
                'available'   => 0,
            ], $stock));
        }

        return $variant;
    }

    private function skus(array $json): array
    {
        return array_map(fn ($r) => $r['sku'], $json);
    }

    public function test_habis_lists_only_zero_or_negative_available(): void
    {
        $this->makeVariant('HABIS-1', [], ['on_hand' => 0, 'available' => 0]);
        $this->makeVariant('ADA-1', [], ['on_hand' => 10, 'available' => 10]);

        $res = $this->getJson('/api/v1/inventory/monitor/out-of-stock?mode=habis')->assertOk();
        $skus = $this->skus($res->json('data'));

        $this->assertContains('HABIS-1', $skus);
        $this->assertNotContains('ADA-1', $skus);
    }

    public function test_minus_lists_only_negative_on_hand(): void
    {
        $this->makeVariant('MINUS-1', [], ['on_hand' => -5, 'available' => -5]);
        $this->makeVariant('HABIS-2', [], ['on_hand' => 0, 'available' => 0]);

        $res = $this->getJson('/api/v1/inventory/monitor/out-of-stock?mode=minus')->assertOk();
        $skus = $this->skus($res->json('data'));

        $this->assertContains('MINUS-1', $skus);
        $this->assertNotContains('HABIS-2', $skus);
    }

    public function test_low_stock_uses_min_stock_threshold_and_safe_stock_target(): void
    {

        $this->makeVariant('TIPIS-1', ['min_stock' => 10, 'safe_stock' => 20], ['on_hand' => 5, 'available' => 5]);

        $this->makeVariant('AMAN-1', ['min_stock' => 10, 'safe_stock' => 20], ['on_hand' => 50, 'available' => 50]);

        $this->makeVariant('NOMIN-1', ['min_stock' => 0, 'safe_stock' => 0], ['on_hand' => 0, 'available' => 0]);

        $res = $this->getJson('/api/v1/inventory/monitor/low-stock')->assertOk();
        $data = collect($res->json('data'));

        $row = $data->firstWhere('sku', 'TIPIS-1');
        $this->assertNotNull($row);
        $this->assertSame(15, $row['qty_to_restock']);
        $this->assertNotContains('AMAN-1', $this->skus($res->json('data')));
        $this->assertNotContains('NOMIN-1', $this->skus($res->json('data')));
    }

    public function test_low_stock_falls_back_to_min_stock_when_safe_stock_zero(): void
    {

        $this->makeVariant('FALLBACK-1', ['min_stock' => 8, 'safe_stock' => 0], ['on_hand' => 2, 'available' => 2]);

        $row = collect($this->getJson('/api/v1/inventory/monitor/low-stock')->json('data'))
            ->firstWhere('sku', 'FALLBACK-1');

        $this->assertNotNull($row);
        $this->assertSame(6, $row['qty_to_restock']);
    }

    public function test_non_stored_products_excluded(): void
    {
        $this->makeVariant('NONSTORE-1', ['min_stock' => 10], ['on_hand' => 0, 'available' => 0], isStored: false);

        $habis = $this->skus($this->getJson('/api/v1/inventory/monitor/out-of-stock?mode=habis')->json('data'));
        $menipis = $this->skus($this->getJson('/api/v1/inventory/monitor/low-stock')->json('data'));

        $this->assertNotContains('NONSTORE-1', $habis);
        $this->assertNotContains('NONSTORE-1', $menipis);
    }

    public function test_search_filter_applies(): void
    {
        $this->makeVariant('CARI-ME', [], ['on_hand' => 0, 'available' => 0]);
        $this->makeVariant('OTHER', [], ['on_hand' => 0, 'available' => 0]);

        $skus = $this->skus($this->getJson('/api/v1/inventory/monitor/out-of-stock?mode=habis&search=CARI-ME')->json('data'));

        $this->assertContains('CARI-ME', $skus);
        $this->assertNotContains('OTHER', $skus);
    }

    public function test_summary_returns_counts(): void
    {
        $this->makeVariant('S-HABIS', [], ['on_hand' => 0, 'available' => 0]);
        $this->makeVariant('S-MINUS', [], ['on_hand' => -1, 'available' => -1]);
        $this->makeVariant('S-TIPIS', ['min_stock' => 10], ['on_hand' => 3, 'available' => 3]);

        $res = $this->getJson('/api/v1/inventory/monitor/summary')->assertOk();
        $summary = $res->json('data');

        $this->assertGreaterThanOrEqual(2, $summary['habis']); 
        $this->assertGreaterThanOrEqual(1, $summary['minus']);
        $this->assertGreaterThanOrEqual(1, $summary['menipis']);
        $this->assertArrayHasKey('on_order', $summary);
    }

    public function test_on_order_and_dipesan_endpoints_ok(): void
    {
        $this->makeVariant('X-1', [], ['on_hand' => 0, 'available' => 0]);

        $this->getJson('/api/v1/inventory/monitor/on-order')
            ->assertOk()->assertJsonStructure(['data', 'meta']);
        $this->getJson('/api/v1/inventory/monitor/out-of-stock?mode=dipesan')
            ->assertOk()->assertJsonStructure(['data', 'meta']);
    }

    public function test_need_restock_alias_still_works(): void
    {
        $this->makeVariant('ALIAS-1', ['min_stock' => 10, 'safe_stock' => 15], ['on_hand' => 2, 'available' => 2]);

        $row = collect($this->getJson('/api/v1/inventory/need-restock')->assertOk()->json('data'))
            ->firstWhere('sku', 'ALIAS-1');

        $this->assertNotNull($row);
        $this->assertSame(13, $row['qty_to_restock']);
    }

    private function ship(string $itemId, int $qty, int $daysAgo): void
    {
        \DB::table('inventory_movements')->insert([
            'id'                 => \Ramsey\Uuid\Uuid::uuid7()->toString(),
            'item_id'            => $itemId,
            'location_id'        => $this->location->id,
            'bin_id'             => null,
            'transaction_number' => 'TST',
            'source'             => 'ORDER_SHIP',
            'qty'                => -abs($qty),
            'balance'            => 0,
            'transaction_date'   => now()->subDays($daysAgo),
            'created_by'         => 'test',
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    public function test_dead_stock_lists_idle_and_never_sold(): void
    {
        $never = $this->makeVariant('DEAD-NEVER', [], ['on_hand' => 30, 'available' => 30]);
        $slow = $this->makeVariant('DEAD-SLOW', [], ['on_hand' => 50, 'available' => 50]);
        $recent = $this->makeVariant('DEAD-RECENT', [], ['on_hand' => 40, 'available' => 40]);
        $this->ship($slow->id, 1, 200);
        $this->ship($recent->id, 5, 5);

        $skus = $this->skus($this->getJson('/api/v1/inventory/monitor/dead-stock?days=90')->assertOk()->json('data'));

        $this->assertContains('DEAD-NEVER', $skus);
        $this->assertContains('DEAD-SLOW', $skus);
        $this->assertNotContains('DEAD-RECENT', $skus);
    }

    public function test_fast_moving_aggregates_window_volume(): void
    {
        $hot = $this->makeVariant('FAST-HOT', [], ['on_hand' => 100, 'available' => 100]);
        $old = $this->makeVariant('FAST-OLD', [], ['on_hand' => 100, 'available' => 100]);
        foreach ([1, 2, 3] as $d) {
            $this->ship($hot->id, 20, $d);
        }
        $this->ship($old->id, 50, 200); 

        $data = collect($this->getJson('/api/v1/inventory/monitor/fast-moving?days=30')->assertOk()->json('data'));
        $row = $data->firstWhere('sku', 'FAST-HOT');

        $this->assertNotNull($row);
        $this->assertSame(60, $row['qty_sold']);
        $this->assertNotContains('FAST-OLD', $data->pluck('sku')->all());
    }

    public function test_estimated_stock_out_projects_days(): void
    {

        $v = $this->makeVariant('ETA-1', [], ['on_hand' => 30, 'available' => 30]);
        $this->ship($v->id, 90, 10);

        $row = collect($this->getJson('/api/v1/inventory/monitor/estimated-stock-out?window=30&threshold=30')->assertOk()->json('data'))
            ->firstWhere('sku', 'ETA-1');

        $this->assertNotNull($row);
        $this->assertSame(10, $row['days_to_out']);
        $this->assertNotNull($row['estimated_date']);
    }
}
