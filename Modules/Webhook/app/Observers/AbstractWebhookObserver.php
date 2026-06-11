<?php

namespace Modules\Webhook\Observers;

use Illuminate\Support\Facades\Log;
use Modules\Webhook\Services\WebhookDispatcherService;

/**
 * Base observer webhook. Memastikan dispatch:
 *  - TIDAK pernah melempar exception ke transaksi domain pemicu (try/catch) → tidak ada 500.
 *  - Hanya memanggil dispatcher (cache-check + ->afterCommit()), tanpa query DB tambahan.
 */
abstract class AbstractWebhookObserver
{
    protected function emit(string $event, array $payload): void
    {
        try {
            app(WebhookDispatcherService::class)->dispatch($event, $payload);
        } catch (\Throwable $e) {
            Log::warning("Webhook emit [{$event}] gagal (diabaikan agar domain tetap jalan): ".$e->getMessage());
        }
    }
}
