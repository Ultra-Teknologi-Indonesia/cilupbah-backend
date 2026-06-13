<?php

namespace Modules\Webhook\Observers;

use Illuminate\Support\Facades\Log;
use Modules\Webhook\Services\WebhookDispatcherService;

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
