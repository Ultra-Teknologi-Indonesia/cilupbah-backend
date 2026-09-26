<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\Cache;
use Modules\Sales\Models\SalesOrder;

final class ShopeeShippingDocumentTypeCache
{
    public function get(SalesOrder $order): ?string
    {
        $key = $this->key($order);
        $value = $key === null ? null : Cache::get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function confirm(SalesOrder $order, string $type): void
    {
        if (($key = $this->key($order)) !== null && in_array($type, ['NORMAL_AIR_WAYBILL', 'THERMAL_AIR_WAYBILL'], true)) {
            Cache::put($key, $type, now()->addHours(6));
        }
    }

    public function forget(SalesOrder $order): void
    {
        if (($key = $this->key($order)) !== null) {
            Cache::forget($key);
        }
    }

    private function key(SalesOrder $order): ?string
    {
        $courier = trim((string) $order->shipping_provider);

        return $courier === '' || empty($order->channel_shop_id) ? null
            : 'shopee-label-type:'.hash('sha256', json_encode([(string) $order->channel_shop_id, $courier], JSON_THROW_ON_ERROR));
    }
}
