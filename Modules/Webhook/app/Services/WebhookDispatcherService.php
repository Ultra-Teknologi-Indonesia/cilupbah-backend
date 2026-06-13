<?php

namespace Modules\Webhook\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Webhook\Jobs\DispatchWebhookEventJob;
use Modules\Webhook\Repositories\WebhookSubscriptionRepository;

/**
 * Titik masuk pemicu webhook dari Observer.
 *
 * AMAN terhadap stock locking (lihat PLAN-WEBHOOKS §6b):
 *  - Hot-path hanya cek cache daftar event aktif. Cache dihangatkan ulang setiap kali
 *    subscription berubah (di luar transaksi domain), jadi cache-miss di dalam lock jarang;
 *    bila terjadi, hanya satu SELECT read-only (tanpa write di dalam lock domain).
 *  - event_id dibangkitkan di sini (stabil) lalu dioper ke job → retry job idempoten.
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

        DispatchWebhookEventJob::dispatch($event, $payload, (string) Str::uuid7())
            ->afterCommit()
            ->onQueue(config('webhook.queue', 'webhooks'));
    }

    /** Daftar event yang punya subscriber aktif (di-cache; dihangatkan saat subscription berubah). */
    private function activeEvents(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            (int) config('webhook.active_events_ttl', 300),
            fn () => $this->repository->activeEventNames()
        );
    }

    /** Hangatkan cache di luar hot-path (dipanggil saat subscription dibuat/diubah/dihapus). */
    public function refreshCache(): void
    {
        Cache::put(
            self::CACHE_KEY,
            $this->repository->activeEventNames(),
            (int) config('webhook.active_events_ttl', 300)
        );
    }

    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
