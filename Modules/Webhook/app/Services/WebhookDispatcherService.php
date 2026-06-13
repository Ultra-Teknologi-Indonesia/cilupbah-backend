<?php

namespace Modules\Webhook\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Webhook\Jobs\DispatchWebhookEventJob;
use Modules\Webhook\Repositories\WebhookSubscriptionRepository;

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

        if (! in_array($event, $active, true) && ! in_array('*', $active, true)) {
            return;
        }

        DispatchWebhookEventJob::dispatch($event, $payload, (string) Str::uuid7())
            ->afterCommit()
            ->onQueue(config('webhook.queue', 'webhooks'));
    }

    private function activeEvents(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            (int) config('webhook.active_events_ttl', 300),
            fn () => $this->repository->activeEventNames()
        );
    }

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
