<?php

declare(strict_types=1);

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
