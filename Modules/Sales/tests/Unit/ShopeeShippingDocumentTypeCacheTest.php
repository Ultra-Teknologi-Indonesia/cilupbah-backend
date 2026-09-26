<?php

declare(strict_types=1);

namespace Modules\Sales\Tests\Unit;

use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\ShopeeShippingDocumentTypeCache;
use Tests\TestCase;

final class ShopeeShippingDocumentTypeCacheTest extends TestCase
{
    public function test_confirmed_document_type_is_scoped_to_shop_and_courier_and_can_be_invalidated(): void
    {
        $cache = app(ShopeeShippingDocumentTypeCache::class);
        $first = new SalesOrder(['channel_shop_id' => 'SHOP-A', 'shipping_provider' => 'SPX']);
        $this->assertNull($cache->get($first));
        $cache->confirm($first, 'THERMAL_AIR_WAYBILL');
        $this->assertSame('THERMAL_AIR_WAYBILL', $cache->get($first));
        $this->assertNull($cache->get(new SalesOrder(['channel_shop_id' => 'SHOP-B', 'shipping_provider' => 'SPX'])));
        $this->assertNull($cache->get(new SalesOrder(['channel_shop_id' => 'SHOP-A', 'shipping_provider' => 'JNT'])));
        $cache->forget($first);
        $this->assertNull($cache->get($first));
    }

    public function test_unknown_courier_and_unsupported_types_are_not_cached(): void
    {
        $cache = app(ShopeeShippingDocumentTypeCache::class);
        $order = new SalesOrder(['channel_shop_id' => 'SHOP-A']);
        $cache->confirm($order, 'THERMAL_AIR_WAYBILL');
        $this->assertNull($cache->get($order));
        $order->shipping_provider = 'SPX';
        $cache->confirm($order, 'UNSUPPORTED');
        $this->assertNull($cache->get($order));
    }
}
