<?php

declare(strict_types=1);

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\CarbonImmutable;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Sales\Exports\SalesOrdersExport;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

final class SalesOrdersExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_resolves_channel_shop_name(): void
    {
        $channel = Channel::create([
            'code' => 'shopee',
            'name' => 'Shopee',
            'is_active' => true,
        ]);
        $shop = ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => 'SALES-EXPORT-SHOP-001',
            'shop_name' => 'Toko Sales Export Uji',
            'is_active' => true,
        ]);

        SalesOrder::factory()->create([
            'status' => 'reserved',
            'channel_shop_id' => $shop->shop_id,
        ]);

        $export = new SalesOrdersExport(null, null, null, null, null, null, null);
        $order = $export->collection()->first();

        $this->assertNotNull($order);
        $this->assertSame('Toko Sales Export Uji', $export->map($order)[8]);
    }

    public function test_empty_stock_export_scopes_shortfall_filter_to_order_items(): void
    {
        SalesOrder::factory()->create([
            'status' => 'reserved',
        ]);

        $export = new SalesOrdersExport('empty-stock', null, null, null, null, null, null);

        $this->assertCount(0, $export->query()->get());
    }

    public function test_export_uses_wib_datetime_boundaries(): void
    {
        $beforeCutover = SalesOrder::factory()->create([
            'salesorder_no' => 'SO-BEFORE-CUTOVER',
            'transaction_date' => CarbonImmutable::create(2026, 9, 16, 15, 59, 59, 'Asia/Jakarta')->utc(),
        ]);
        $atCutover = SalesOrder::factory()->create([
            'salesorder_no' => 'SO-AT-CUTOVER',
            'transaction_date' => CarbonImmutable::create(2026, 9, 16, 16, 0, 0, 'Asia/Jakarta')->utc(),
        ]);

        $export = new SalesOrdersExport(
            null,
            '2026-09-16 16:00:00',
            '2026-09-16 16:00:00',
            null,
            null,
            null,
            null,
        );

        $orderNumbers = $export->query()->pluck('salesorder_no')->all();

        $this->assertContains($atCutover->salesorder_no, $orderNumbers);
        $this->assertNotContains($beforeCutover->salesorder_no, $orderNumbers);
    }

    public function test_date_only_export_still_covers_the_full_business_day(): void
    {
        $inside = SalesOrder::factory()->create([
            'salesorder_no' => 'SO-WIB-DAY-END',
            'transaction_date' => CarbonImmutable::create(2026, 9, 16, 23, 59, 59, 'Asia/Jakarta')->utc(),
        ]);

        $export = new SalesOrdersExport(null, '2026-09-16', '2026-09-16', null, null, null, null);

        $this->assertContains($inside->salesorder_no, $export->query()->pluck('salesorder_no')->all());
    }
}
