<?php

namespace App\Traits;

use Illuminate\Support\Facades\Cache;

trait StockLockable
{
    protected function withStockLock(string $itemId, string $locationId, callable $callback, int $ttl = 15)
    {
        $lockKey = "stock_lock:{$itemId}:{$locationId}";
        $lock = Cache::lock($lockKey, $ttl);

        try {
            $lock->block(5);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            throw new \RuntimeException("Gagal mendapatkan lock stok untuk item {$itemId} di lokasi {$locationId}.");
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
