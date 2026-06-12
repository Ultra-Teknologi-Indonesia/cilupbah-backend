<?php

namespace Modules\Webhook\Services;

use Illuminate\Support\Facades\Cache;
use Modules\Webhook\Jobs\DispatchWebhookEventJob;
use Modules\Webhook\Repositories\WebhookSubscriptionRepository;

/**
 * Titik masuk pemicu webhook dari Observer.
 *
 * AMAN terhadap stock locking (lihat PLAN-WEBHOOKS §6b):
 *  - Hot-path hanya cache lookup (TANPA query DB di dalam transaksi/lock).
 *  - Pengiriman ditunda via ->afterCommit() → job masuk antrean SETELAH commit & lock dilepas.
 *  - Rollback transaksi → job tidak pernah ter-enqueue (notifikasi konsisten).
 */
class WebhookDispatcherService
{
    private const CACHE_KEY = 'webhook:active-events';

    public function __construct(
        private readonly WebhookSubscriptionRepository $repository,
    ) {
    }

    public function dispatch(string $event, array $payload): void
    {
        $active = $this->activeEvents();

        // Tidak ada subscriber untuk event ini → no-op (nol overhead, nol job).
        if (! in_array($event, $active, true) && ! in_array('*', $active, true)) {
            return;
        }

        DispatchWebhookEventJob::dispatch($event, $payload)
            ->afterCommit()
            ->onQueue(config('webhook.queue', 'webhooks'));
    }

    /** Daftar event yang punya subscriber aktif (di-cache; refresh saat subscription berubah). */
    private function activeEvents(): array
    {
        return Cache::remember(self::CACHE_KEY, 60, fn () => $this->repository->activeEventNames());
    }

    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
