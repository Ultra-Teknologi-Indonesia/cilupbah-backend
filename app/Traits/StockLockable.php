<?php

namespace App\Traits;

use Illuminate\Support\Facades\Cache;

trait StockLockable
{
    protected function withStockLock(string $itemId, string $locationId, callable $callback, int $ttl = 10)
    {
        $lockKey = "stock_lock:{$itemId}:{$locationId}";
        $lock = Cache::lock($lockKey, $ttl);

        if ($lock->get()) {
            try {
                return $callback();
            } finally {
                $lock->release();
            }
        }

        throw new \RuntimeException("Gagal mendapatkan lock stok untuk item {$itemId} di lokasi {$locationId}.");
    }
}
